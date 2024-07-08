<?php

/**
 * Classe para criar formulários.
 * 
 * @author Charles Batista <charles.batista@tjce.jus.br>
 */
class CBBox extends CBBox_Helpers {

	/**
	 * Array com todas as meta boxes a serem montadas
	 * 
	 * @var array
	 */
	private array $meta_boxes;

	/**
	 * ID do post relacionado ao formulário.
	 * 
	 * @var int|null
	 */
	private mixed $post_id;

	/**
	 * ID da página ou post type onde as meta boxes serão renderizadas.
	 * 
	 * @var string
	 */
	private string $pagina_id;

	/**
	 * Construtor da classe.
	 * 
	 * @param string 	$pagina_id 		ID da página ou post type onde as meta boxes serão renderizadas.
	 * @param array 	$meta_boxes 	Array com todas as meta boxes a serem montadas.
	 */
	public function __construct(string $pagina_id, array $meta_boxes) {
		$this->pagina_id  = $pagina_id;
		$this->meta_boxes = $meta_boxes;

		$this->gera_meta_boxes();

		add_action('admin_enqueue_scripts', [$this, 'enqueue_styles']);
	}

	/**
	 * Com base no array de meta boxes definidos, carrega os dados e adiciona
	 * as meta boxes no contexto do WordPress.
	 *
	 * @return void
	 */
	public function gera_meta_boxes() {
		// itera sobre o array de meta boxes para criar cada uma
		foreach ($this->meta_boxes as &$meta_box) {
			// adicionamos o action para criar a meta box
			add_action('add_meta_boxes', function ($post_type, $post) use (&$meta_box) {
				$this->adiciona_metabox($meta_box["id"], $meta_box["titulo"], 'meta_box', $this->pagina_id, [
					'post_id' => $post->ID,
					'campos'  => $meta_box["campos"]
				]);
			}, 10, 2);

			// adicionamos o action para salvar os dados dos campos customizados
			add_action("save_post_{$this->pagina_id}", function ($post_id) use ($meta_box) {
				static $erros = [];
				if ($this->verifica_requisicao_valida($meta_box["id"]) === false) {
					$erros[] = "nonce_invalido";
				} elseif ($this->valida_campos_personalizados($post_id, $meta_box["id"], $meta_box["campos"]) === false) {
					$erros[] = "validacao_campos";
				} else {
					call_user_func([$this, 'salva_valores'], $post_id, $meta_box["id"], $meta_box["campos"]);
				}

				// Adiciona um único redirecionamento no final do último meta box processado
				add_action('shutdown', function () use ($erros, $post_id) {
					if (!empty($erros)) {
						$erro_query = http_build_query(['erro' => implode(',', $erros)]);
						wp_redirect(admin_url("post.php?post={$post_id}&action=edit&" . $erro_query));
						exit();
					}
				});
			});
		}
	}

	/**
	 * Valida todos os campos de um meta box, incluindo campos em grupos, com base em regras de validação definidas.
	 * 
	 * Inicia o processo de validação dos campos, atribuindo um ID de post baseado em timestamp se o ID fornecido for nulo.
	 * Chama a função de validação recursiva para tratar todos os campos e subcampos. Após a validação, verifica e 
	 * salva quaisquer erros encontrados. Retorna true se nenhum erro foi encontrado, indicando sucesso na validação,
	 * ou false se algum erro foi identificado.
	 *
	 * @param int|null 	$post_id 		O ID do post que está sendo validado, ou null para novos posts sem ID atribuído.
	 * @param string 	$meta_box_id 	O identificador do meta box que está sendo processado.
	 * @param array 	&$campos 		Array de campos e/ou grupos de campos a serem validados.
	 * @return bool						Retorna true se nenhum erro foi encontrado, false caso contrário.
	 */
	private function valida_campos_personalizados(int|null $post_id, string $meta_box_id, array &$campos) {
		$erros = [];

		// Atribui um timestamp como ID do post se o ID original for nulo, garantindo um identificador único.
		$post_id = $post_id ?? time();

		// Chama o método recursivo para validar todos os campos e subcampos.
		$this->valida_campos_recursivamente($post_id, $campos, $erros);

		// Verifica e salva os erros encontrados, retornando o resultado da operação.
		return $this->verificar_e_salvar_erros($erros, $post_id, $meta_box_id);
	}

	/**
	 * Valida recursivamente campos e subcampos dentro de grupos, aplicando as validações especificadas para cada campo.
	 * 
	 * Este método itera sobre o array de campos, aplicando as validações necessárias para cada campo ou chamando a si mesmo
	 * recursivamente para grupos de campos. Utiliza um prefixo para gerar nomes completos de campos em grupos para
	 * manter a integridade da estrutura de dados e permitir validações contextuais corretas.
	 * 
	 * @param int 		$post_id 	O ID do post que está sendo validado, usado para gerenciar valores temporários.
	 * @param array 	&$campos 	Array de campos e/ou grupos de campos a serem validados.
	 * @param array 	&$erros 	Array referenciado para acumular os erros encontrados durante a validação.
	 * @param string 	$prefixo 	Prefixo acumulado para nomes de campos dentro de grupos, usado para formar o nome completo do campo.
	 */
	private function valida_campos_recursivamente(int $post_id, array &$campos, &$erros, $prefixo = '') {
		if (!empty($campos)) {
			foreach ($campos as $campo) {
				if (!empty($campo["name"])) {
					$campo_nome_completo = $prefixo . $campo["name"];

					// recebe o valor do formulário
					$valor = $_POST[$campo_nome_completo] ?? '';

					// salva o valor do campo num transient para permanecer o valor 
					// que o usuário enviou caso haja falha na validação.
					set_transient(join('_', [$post_id, $campo_nome_completo]), $valor, 60);

					// Verifica se o campo é obrigatório e se está vazio
					if (!empty($campo["atributos"]["required"]) && empty($valor)) {
						$erros[$campo_nome_completo] = $campo["label"] . ' é um campo obrigatório.';
					}

					// Processamento de validações específicas
					if (!empty($campo["validacao"]) && is_array($campo["validacao"])) {
						$this->aplica_validacoes($post_id, $campos, $campo, $valor, $erros, $campo_nome_completo);
					}

					// Se o campo é um grupo, recursivamente valida seus subcampos
					if (isset($campo['tipo']) && $campo['tipo'] === 'grupo' && isset($campo['campos'])) {
						$novo_prefixo = $campo_nome_completo . '_';
						$this->valida_campos_recursivamente($post_id, $campo['campos'], $erros, $novo_prefixo);
					}
				}
			}
		}
	}

	/**
	 * Aplica validações específicas a um campo com base em regras definidas.
	 * 
	 * Este método é responsável por aplicar uma série de validações a um campo específico. Ele verifica
	 * cada tipo de validação definida no array de validações do campo e executa as verificações
	 * correspondentes. Suporta validações para CPF, CNPJ, datas e URLs.
	 *
	 * @param int 		$post_id 				O ID do post que está sendo validado, usado para gerenciar valores temporários.
	 * @param array 	&$campos 				Array contendo todos os campos.
	 * @param array 	$campo 					Dados do campo que incluem nome, tipo e outras configurações específicas.
	 * @param mixed 	$valor 					Valor atual do campo obtido a partir do formulário.
	 * @param array 	&$erros 				Referência ao array que armazena todos os erros de validação encontrados.
	 * @param string 	$campo_nome_completo 	Nome completo do campo, incluindo prefixos de grupos para identificação única.
	 */
	private function aplica_validacoes(int $post_id, array &$campos, array $campo, $valor, &$erros, $campo_nome_completo) {
		foreach ($campo["validacao"] as $tipo) {
			if (strpos($tipo, ':') !== false) {
				list($validacao, $parametros) = explode(':', $tipo, 2);
			} else {
				$validacao = $tipo;
				$parametros = null;
			}

			switch ($validacao) {
				case 'cpf':
					if (!empty($valor) && !$this->valida_cpf($valor)) {
						$erros[$campo_nome_completo] = 'O CPF informado é inválido.';
					}
					break;

				case 'cnpj':
					if (!empty($valor) && !$this->valida_cnpj($valor)) {
						$erros[$campo_nome_completo] = 'O CNPJ informado é inválido.';
					}
					break;

				case 'data':
					// Define um formato padrão para as datas caso um não seja especificado
					$formato = !isset($parametros) ? 'd/m/Y' : $parametros;

					if (!empty($valor) && !$this->valida_data($valor, $formato)) {
						$erros[$campo_nome_completo] = 'A data fornecida é inválida ou não está no formato esperado (' . $formato . ').';
					}
					break;

				case 'ate_hoje':
					// Define um formato padrão para as datas caso um não seja especificado
					$formato = !isset($parametros) ? 'd/m/Y' : $parametros;

					if (!empty($valor) && $this->data_maior_que_hoje($valor, $formato)) {
						$erros[$campo_nome_completo] = 'A data não pode ser maior que a data de hoje.';
					}
					break;

				case 'unico':
					if ($parametros) {
						$campos_relacionados = explode(',', $parametros);

						// Verificar se realmente existem múltiplos campos
						if (count($campos_relacionados) > 1 && $this->valida_campos_relacionados($campos_relacionados)) {
							$labels_relacionados = $this->obter_labels_por_nomes($campos, $campos_relacionados);

							foreach ($campos_relacionados as $campo_relacionado) {
								$valor_relacionado = $_POST[$campo_relacionado] ?? '';
								if (!empty($valor) && !empty($valor_relacionado)) {
									$texto = 'Apenas um dos campos (' . join(', ', $labels_relacionados) . ') pode ser preenchido.';
									$erros[$campo_nome_completo]     = $texto;
									$erros[$campo_relacionado] = $texto;
								}
							}
						}
					}
					break;

				case 'url':
					// Define um parâmetro para o tipo de URL que espera ser validado, padrão para qualquer URL.
					$tipo_url = !isset($parametros) ? FILTER_VALIDATE_URL : $parametros;

					// Valida se o campo não está vazio e se a URL é inválida
					if (!empty($valor) && !filter_var($valor, $tipo_url)) {
						$erros[$campo_nome_completo] = 'A URL fornecida é inválida.';
					}
					break;
				case 'maior_ou_igual':
					$this->verifica_maior_ou_igual($post_id, $campos, $valor, $parametros, $campo_nome_completo, $erros);
					break;
			}
		}
	}

	/**
	 * Verifica se o valor de um campo é maior ou igual a um valor específico ou ao valor de outro campo.
	 *
	 *@param int 		$post_id 				O ID do post que está sendo validado, usado para gerenciar valores temporários.
	 * @param array 	&$campos 				Referência do array de todos os campos.
	 * @param mixed 	$valor 					Valor atual do campo que está sendo validado.
	 * @param mixed 	$parametro_comparacao 	Pode ser o nome de outro campo ou um valor fixo para comparação.
	 * @param string 	$campo_nome_completo 	Nome completo do campo para identificação em mensagens de erro.
	 * @param array 	&$erros 				Referência ao array que armazena todos os erros de validação encontrados.
	 * @return array 							Retorna um array de erros, possivelmente vazio se não houver erros.
	 */
	protected function verifica_maior_ou_igual(int $post_id, array &$campos, mixed $valor, mixed $parametro_comparacao, string $campo_nome_completo, &$erros) {
		if (empty($valor)) {
			return;
		}

		// obtém os nomes de todos os campos
		$nomes_campos = array_column($campos, 'name');

		// Verifica se o parâmetro de comparação é um valor ou um campo.
		$indice = array_search($parametro_comparacao, $nomes_campos);
		if ($indice !== false) {
			// O parâmetro é um nome de campo, obtém o valor do campo correspondente
			$valor_campo_comparado = get_transient(join('_', [$post_id, $parametro_comparacao]));

			// Compara os valores
			if ($valor < $valor_campo_comparado) {
				$erros[$campo_nome_completo] = 'O valor deve ser maior ou igual ao valor do campo <b>' . $campos[$indice]["label"] . '</b>: ' . $valor_campo_comparado . '.';
			}
		} else {
			// Não encontrou o nome do campo, considera que é um valor fixo
			if ($valor < $parametro_comparacao) {
				$erros[$campo_nome_completo] = 'O valor deve ser maior ou igual a ' . $parametro_comparacao . '.';
			}
		}
	}

	/**
	 * Verifica a existência de erros e os salva em um transient no WordPress.
	 * 
	 * Este método protegido é utilizado para verificar se há erros associados ao processo
	 * de validação de dados em um meta box. Caso erros sejam encontrados, eles são salvos 
	 * temporariamente em um transient, que é uma forma de armazenar dados temporários no 
	 * banco de dados do WordPress. O transient tem uma duração de 60 segundos, após o qual 
	 * expira automaticamente.
	 *
	 * @param array 	$erros 			Array contendo mensagens de erro. Cada item do array representa um erro específico encontrado durante o processo.
	 * @param int 		$post_id 		O ID do post no qual os erros foram verificados. Isso ajuda a identificar e associar os erros ao contexto correto dentro do WordPress.
	 * @param string 	$meta_box_id 	O ID do meta box relacionado, utilizado para construir o identificador único do transient.
	 * @return bool 					Retorna false se erros foram encontrados e salvos no transient, ou true se nenhum erro foi encontrado, indicando que o processo pode continuar.
	 */
	protected function verificar_e_salvar_erros($erros, $post_id, $meta_box_id) {
		if (!empty($erros)) {
			$transient = join('_', [$meta_box_id, $post_id]);
			set_transient($transient, $erros, 60); // Dura 60 segundos
			return false;
		}

		return true;
	}

	/**
	 * Renderiza uma metabox dentro da interface de edição de posts no WordPress.
	 *
	 * Este método é responsável por inicializar uma metabox, verificando a segurança
	 * com um nonce e populando os campos personalizados com os valores existentes.
	 *
	 * @param WP_Post 	$post 			O objeto do post atual sendo editado.
	 * @param array 	$meta_box 		Uma matriz contendo informações sobre a metabox, incluindo os campos a serem renderizados.
	 * @param string 	$meta_box_id 	Identificador único para a metabox, utilizado para segurança na verificação do nonce.
	 * @return void 					Não retorna nada, pois sua função é renderizar a metabox diretamente na página de edição.
	 */
	public function meta_box($post, $meta_box, $meta_box_id) {
		// Gera um campo oculto para a validação de segurança, associando este formulário com a metabox.
		wp_nonce_field("{$meta_box_id}_nonce", "{$meta_box_id}_nonce");

		// renderiza o formulário
		$this->renderiza($meta_box_id, $meta_box["args"]["post_id"], $meta_box["args"]["campos"]);
	}

	/**
	 * Salva os valores dos campos de um meta box no banco de dados.
	 *
	 * Este método itera sobre um array de campos fornecidos, determinando se são campos
	 * simples ou grupos de campos. Campos simples são salvos diretamente, enquanto os campos
	 * de grupo têm seus subcampos processados individualmente com um prefixo derivado do nome do grupo.
	 *
	 * @param int 		$post_id 		O ID do post para o qual os meta dados estão sendo salvos.
	 * @param string 	$meta_box_id 	O ID do meta box que está sendo processado.
	 * @param array 	$campos 		Array de campos ou grupos de campos para serem processados e salvos.
	 */
	private function salva_valores(int $post_id, string $meta_box_id, array $campos) {
		if (is_array($campos) && !empty($campos)) {
			foreach ($campos as $campo) {
				if ($campo['tipo'] === 'grupo' && isset($campo['campos'])) {
					// Processa cada campo dentro do grupo
					foreach ($campo['campos'] as $subcampo) {
						$this->salva_campo($post_id, $subcampo, $campo['name'] . '_');
					}
				} else {
					$this->salva_campo($post_id, $campo);
				}
			}
		}
	}

	/**
	 * Salva um campo individual no banco de dados, aplicando sanitização e formatação.
	 *
	 * Este método trata um campo individual, aplicando sanitização ao valor e, se necessário,
	 * convertendo o formato do dado antes de salvá-lo como meta dado de um post. Pode ser usado
	 * diretamente para campos simples ou chamado por `salva_valores` para campos dentro de grupos,
	 * neste caso um prefixo é adicionado ao nome do campo para refletir a estrutura do grupo.
	 *
	 * @param int $post_id O ID do post no qual os meta dados serão salvos.
	 * @param array $campo Array associativo que descreve as propriedades do campo, incluindo nome, tipo, e formatos opcionais.
	 * @param string $prefixo_nome_campo Prefixo opcional para o nome do campo, usado principalmente para campos que são parte de um grupo.
	 */
	private function salva_campo(int $post_id, array $campo, string $prefixo_nome_campo = '') {
		$nome_campo_completo = $prefixo_nome_campo . $campo['name'];
		$valor_campo         = sanitize_text_field($_POST[$nome_campo_completo] ?? null);

		if (!empty($valor_campo) && isset($campo['formatos'])) {
			$valor_campo = $this->formata_data(
				$valor_campo,
				$campo['formatos']['exibir'] ?? '',
				$campo['formatos']['salvar'] ?? ''
			);
		}

		$this->mantem_valor_bd($post_id, $nome_campo_completo, $valor_campo);
	}

	/**
	 * Popula os valores dos campos de meta box com dados do post e possíveis valores temporários.
	 * 
	 * Este método itera sobre um array de campos, tratando cada campo individualmente. Se o campo
	 * for do tipo 'grupo', a função é chamada recursivamente para cada subcampo do grupo. Caso
	 * contrário, cada campo é tratado para preencher seu valor a partir dos metadados do post
	 * ou valores temporários armazenados.
	 *
	 * @param int|null 	$post_id 		O ID do post do qual os metadados são recuperados.
	 * @param array 	&$campos 		Array de campos para serem populados com valores.
	 * @param string 	$prefixo_grupo	Prefixo do grupo do qual alguns campos fazem parte.
	 * @return array 					Retorna o array de campos com os valores populados.
	 */
	private function popula_valores_campos(int|null $post_id, array &$campos, string $prefixo_grupo = '') {
		// obtém todos os metadados do post de uma vez
		$post_meta = get_post_meta($post_id);

		// loop sobre cada campo fornecido para popular com os valores dos metadados
		foreach ($campos as &$campo) {
			// Verifica se é um grupo de campos
			if (isset($campo['tipo']) && $campo['tipo'] === 'grupo' && isset($campo['campos'])) {
				// Chama a função recursivamente para tratar os subcampos, passando o novo prefixo
				$novo_prefixo = $prefixo_grupo . $campo['name'] . '_';
				$this->popula_valores_campos($post_id, $campo['campos'], $novo_prefixo);
			} else {
				// Popula campos simples ou campos dentro de grupos
				$this->popula_valor_campo($post_meta, $campo, $post_id, $prefixo_grupo);
			}
		}

		return $campos;
	}

	/**
	 * Auxilia na população dos valores de um campo individual, lidando com tipos especiais
	 * de campos e conversão de formatos.
	 *
	 * @param array 	$post_meta 		Array contendo todos os metadados do post.
	 * @param array 	&$campo 		Referência ao campo que será preenchido com o valor.
	 * @param string 	$prefixo_grupo	Prefixo do grupo do qual alguns campos fazem parte.
	 * @param int 		$post_id 		O ID do post usado para possíveis operações com transients.
	 */
	private function popula_valor_campo(array $post_meta, array &$campo, int $post_id, string $prefixo_grupo = '') {
		if (isset($campo['name'])) {
			$nome_completo = $prefixo_grupo . $campo['name'];

			if ($campo['tipo'] === 'wp_media') {
				$campo['valor'] = [
					'url'     => $post_meta[$nome_completo . '_url'][0] ?? null,
					'tamanho' => $post_meta[$nome_completo . '_tamanho'][0] ?? null
				];
			} else {
				$transient       = join('_', [$post_id, $nome_completo]);
				$valor_transient = get_transient($transient);

				if ($valor_transient !== false) {
					$campo['valor'] = $valor_transient;
				} else {
					$campo['valor'] = $post_meta[$nome_completo][0] ?? $campo["valor"] ?? null;

					if (isset($campo['formatos']['exibir']) && isset($campo['formatos']['salvar'])) {
						$campo['valor'] = $this->formata_data(
							$campo['valor'],
							$campo['formatos']['salvar'],
							$campo['formatos']['exibir']
						);
					}
				}

				delete_transient($transient);
			}
		}
	}

	/**
	 * Verifica se um valor foi enviado e salva no banco de dados como postmeta.
	 * Se existir, atualiza o valor; se não for enviado, deleta o registro.
	 *
	 * @param int			$post_id 		O ID do post ao qual o meta dado pertence.
	 * @param string		$meta_key 		A chave do meta dado.
	 * @param mixed			$meta_value 	O valor do meta dado.
	 *
	 * @return void
	 */
	private function mantem_valor_bd($post_id, $meta_key, $meta_value) {
		if (!empty($meta_value)) {
			update_post_meta($post_id, $meta_key, $meta_value);
		} else {
			delete_post_meta($post_id, $meta_key);
		}
	}

	/**
	 * Verifica se a requisição para salvar é válida.
	 *
	 * @param string		$meta_box_id	O ID da meta box.
	 * @return bool							Retorna true se a requisição for válida, caso contrário false.
	 */
	private function verifica_requisicao_valida(string $meta_box_id) {
		// Verifica se o "nonce" de validação da requisição existe.
		if (!isset($_POST[$meta_box_id . '_nonce'])) {
			return false;
		}

		// Verifica se o nonce é válido.
		if (!wp_verify_nonce($_POST[$meta_box_id . '_nonce'], $meta_box_id . '_nonce')) {
			return false;
		}

		// Verifica se está sendo realizado um autosave.
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return false;
		}

		// Verifica se a requisição é AJAX.
		if (defined('DOING_AJAX') && DOING_AJAX) {
			return false;
		}

		return true;
	}

	/**
	 * Permite criar uma metabox
	 *
	 * @param string $meta_box_id
	 * @param string $titulo
	 * @param string $callback
	 * @param string $post_type
	 * @param array $callback_args
	 * @return void
	 */
	public function adiciona_metabox(string $meta_box_id, string $titulo, string $callback = 'meta_box', string $post_type, array $callback_args = []) {
		add_meta_box(
			$meta_box_id,
			$titulo,
			function ($post, $metabox) use ($callback, $meta_box_id) {
				call_user_func([$this, $callback], $post, $metabox, $meta_box_id);
			},
			$post_type,
			'advanced',
			'default',
			$callback_args
		);
	}

	/**
	 * Renderiza o formulário.
	 *
	 * Este método itera sobre todos os campos fornecidos e os renderiza dentro de uma tabela HTML.
	 * 
	 * @param string 	$meta_box_id	ID da meta box a ser renderizada.
	 * @param int|null 	$post_id		ID do post editado.
	 * @param array 	$campos			Array com os campos da meta box.
	 * 
	 * @return void
	 */
	public function renderiza(string $meta_box_id, int|null $post_id, array $campos) {
		$form = '<table class="form-table" role="presentation">';
		$form .= '<tbody>';

		// define o nome do transient para carregamento dos erros
		$transient      = join('_', [$meta_box_id, $post_id]);
		$meta_box_erros = get_transient($transient);

		$this->exibe_mensagem_erro($meta_box_erros);

		// popula os campos com os dados do banco de dados
		$campos = $this->popula_valores_campos($post_id, $campos);

		// itera sobre todos os campos para renderizá-los
		foreach ($campos as $campo) {
			$form .= $this->renderiza_campo($campo, $meta_box_erros);
		}

		// deleta o transient de erros
		delete_transient($transient);

		$form .= '</tbody>';
		echo $form .= '</table>';
	}

	/**
	 * Exibe uma mensagem de erro única se houver erros nos meta boxes e a mensagem ainda não tiver sido exibida.
	 *
	 * Utiliza uma variável estática para garantir que a mensagem de erro seja exibida apenas uma vez
	 * durante a execução do script, evitando redundâncias na interface do usuário.
	 *
	 * @param array $meta_box_erros Array contendo os erros encontrados nos meta boxes.
	 *                              A função verifica se este array não está vazio para proceder com a exibição da mensagem.
	 */
	function exibe_mensagem_erro($meta_box_erros) {
		// Variável estática para rastrear se a mensagem de erro já foi exibida
		static $mensagem_exibida = false;

		// Verifica se há erros e se a mensagem ainda não foi exibida
		if (!empty($meta_box_erros) && !$mensagem_exibida) {
			echo '<div class="notice notice-error"><p>';
			echo 'Foram encontrados erros em um ou mais campos do formulário! Verifique-os antes de salvar novamente.';
			echo '</p></div>';
			$mensagem_exibida = true;  // Marca que a mensagem foi exibida, para evitar repetição
		}
	}

	/**
	 * Renderiza a área que compõe o fieldset de um campo ou grupo de campos.
	 *
	 * @param array $campo 				Array associativo contendo as informações do campo.
	 * @param array $meta_box_erros 	Array associativo contendo mensagens de erro.
	 * 
	 * @return void
	 */
	private function renderiza_campo($campo, $meta_box_erros) {
		// verifica se o campo é obrigatório e adiciona o "*" na frente:
		$label_obrigatorio = isset($campo["atributos"]["required"]) ? '*' : '';

		$area_campo = '<tr class="campo-formulario ' . $campo["tipo"] . '">';
		$area_campo .= '<th scope="row">' . $campo["label"] . $label_obrigatorio . ':</th>';
		$area_campo .= '<td>';
		$area_campo .= $this->renderiza_fieldset($campo, $meta_box_erros);
		$area_campo .= '</td>';
		return $area_campo .= '</tr>';
	}

	/**
	 * Renderiza um único campo do formulário.
	 *
	 * @param array $campo 				Array associativo contendo as informações do campo.
	 * @param array $meta_box_erros 	Array associativo contendo mensagens de erro.
	 * @param array $grupo_id  		 	ID do grupo do qual o campo faz parte.
	 * 
	 * @return string
	 */
	private function renderiza_fieldset($campo, $meta_box_erros, $grupo_id = null) {
		// se uma classe css estiver sido definida
		$css_class = (isset($campo["class"])) ? ' class="' . $campo["class"] . '"' : null;

		// iniciamos a variável que irá guardar os atributos adicionais do campo
		// podendo ser qualquer coisa, como atributos "data-" 
		$atributos = $this->obtem_atributos_campo($campo, $css_class);

		// define o valor do campo de acordo com o valor passado pelos parâmetros
		$valor = $campo["valor"] ?? null;

		// inicia o fieldset
		$fieldset = '<fieldset id="campo-' . (isset($grupo_id) ? $grupo_id . '-' : null) . $campo["name"] . '">';

		// renderiza o campo específico de acordo com o seu tipo
		switch ($campo["tipo"]) {
			case 'text':
				$fieldset .= $this->renderiza_campo_texto($campo, $valor, $atributos, $grupo_id);
				break;
			case 'date':
				$fieldset .= $this->renderiza_campo_data($campo, $valor, $atributos, $grupo_id);
				break;
			case 'number':
				$fieldset .= $this->renderiza_campo_numero($campo, $valor, $atributos, $grupo_id);
				break;
			case 'textarea':
				$fieldset .= $this->renderiza_campo_textarea($campo, $valor, $atributos, $grupo_id);
				break;
			case 'wp_media':
				$fieldset .= $this->renderiza_campo_wp_media($campo, $valor, $atributos, $grupo_id);
				break;
			case 'select':
				$fieldset .= $this->renderiza_campo_select($campo, $valor, $atributos, $grupo_id);
				break;
			case 'grupo':
				$fieldset .= $this->renderiza_campo_grupo($campo, $meta_box_erros);
				break;
		}

		// exibimos uma descrição para o campo caso tenha sido configurada
		if (isset($campo["descricao"])) {
			$fieldset .=  '<p class="descricao">' . $campo["descricao"] . '</p>';
		}

		// exibimos o erro do campo caso não tenha passado na validação
		$nome_campo_erros = (isset($grupo_id) ? $grupo_id . '_' : null) . $campo["name"];

		if (isset($meta_box_erros[$nome_campo_erros])) {
			$fieldset .=  '<p class="erro"><span class="dashicons dashicons-no"></span> ' . $meta_box_erros[$nome_campo_erros] . '</p>';
		}

		return $fieldset .= '</fieldset>';
	}

	/**
	 * Obtém os atributos adicionais de um campo.
	 *
	 * @param array 		$campo 			Array associativo contendo as informações do campo.
	 * @param string|null 	$css_class 		String contendo as classes CSS adicionais.
	 * @return string 						String contendo os atributos adicionais do campo.
	 */
	private function obtem_atributos_campo(array $campo, string|null $css_class) {
		$atributos = '';
		if (isset($campo["atributos"])) {
			foreach ($campo["atributos"] as $key => $value) {
				if ($value != "") {
					$atributos .= ' ' . $key . '="' . $value . '"';
				} else {
					$atributos .= ' ' . $key;
				}
			}
		}
		$atributos .= $css_class;

		return $atributos;
	}

	/**
	 * Apenas define o nome/id do campo com o sufixo ID do grupo
	 *
	 * @param string		$nome_campo		O nome/id do campo.
	 * @param string|null	$nome_campo		O nome/id do grupo.
	 * @return string	
	 */
	private function adiciona_nome_grupo_campo(string $nome_campo, ?string $grupo_id) {
		if (!empty($grupo_id)) {
			return join('_', [$grupo_id, $nome_campo]);
		}

		return $nome_campo;
	}

	/**
	 * Renderiza um campo de texto.
	 *
	 * @param array 	$campo 		Array associativo contendo as informações do campo.
	 * @param mixed 	$valor 		Valor inicial do campo.
	 * @param string 	$atributos 	Atributos adicionais do campo.
	 * @param string 	$grupo_id  	ID do grupo do qual o campo faz parte.
	 * 
	 * @return string
	 */
	private function renderiza_campo_texto(array $campo, $valor, string $atributos, ?string $grupo_id) {
		$nome_campo = $this->adiciona_nome_grupo_campo($campo["name"], $grupo_id);
		return '<input type="text" id="' . $nome_campo . '" name="' . $nome_campo . '" value="' . $valor . '" ' . $atributos . '>';
	}

	/**
	 * Renderiza um campo de data.
	 *
	 * @param array 	$campo 		Array associativo contendo as informações do campo.
	 * @param mixed 	$valor 		Valor inicial do campo.
	 * @param string 	$atributos 	Atributos adicionais do campo.
	 * @param string 	$grupo_id  	ID do grupo do qual o campo faz parte.
	 * 
	 * @return string
	 */
	private function renderiza_campo_data(array $campo, $valor, string $atributos, ?string $grupo_id) {
		$formato    = !empty($campo["formato"]) ? $campo["formato"] : 'd/m/Y';
		$nome_campo = $this->adiciona_nome_grupo_campo($campo["name"], $grupo_id);
		return '<input type="date" id="' . $nome_campo . '" format="' . $formato  . '" name="' . $nome_campo . '" value="' . $valor . '" ' . $atributos . '>';
	}

	/**
	 * Renderiza um campo numérico.
	 *
	 * @param array 	$campo 		Array associativo contendo as informações do campo.
	 * @param mixed 	$valor 		Valor inicial do campo.
	 * @param string 	$atributos 	Atributos adicionais do campo.
	 * @param string 	$grupo_id  	ID do grupo do qual o campo faz parte.
	 * 
	 * @return string
	 */
	private function renderiza_campo_numero(array $campo, $valor, string $atributos, ?string $grupo_id) {
		$nome_campo = $this->adiciona_nome_grupo_campo($campo["name"], $grupo_id);
		return '<input type="number" id="' . $nome_campo . '" name="' . $nome_campo . '" value="' . $valor . '" ' . $atributos . '>';
	}

	/**
	 * Renderiza um campo de área de texto.
	 *
	 * @param array 	$campo 		Array associativo contendo as informações do campo.
	 * @param mixed 	$valor 		Valor inicial do campo.
	 * @param string 	$atributos 	Atributos adicionais do campo.
	 * @param string 	$grupo_id  	ID do grupo do qual o campo faz parte.
	 * 
	 * @return string
	 */
	private function renderiza_campo_textarea($campo, $valor, $atributos, ?string $grupo_id) {
		$nome_campo = $this->adiciona_nome_grupo_campo($campo["name"], $grupo_id);
		return '<textarea id="' . $nome_campo . '" name="' . $nome_campo . '" ' . $atributos . '>' . htmlentities($valor) . '</textarea>';
	}

	/**
	 * Renderiza um campo para upload de mídia WordPress.
	 *
	 * @param array 	$campo 		Array associativo contendo as informações do campo.
	 * @param mixed 	$valor 		Valor inicial do campo.
	 * @param string 	$atributos 	Atributos adicionais do campo.
	 * @param string 	$grupo_id  	ID do grupo do qual o campo faz parte.
	 * 
	 * @return string
	 */
	private function renderiza_campo_wp_media($campo, $valor, $atributos, ?string $grupo_id) {
		$nome_campo = $this->adiciona_nome_grupo_campo($campo["name"], $grupo_id);

		$wp_media = '<p><input type="text" id="' . $nome_campo . '_url" name="' . $nome_campo  . '_url" value="' . $valor["url"] . '" placeholder="Nenhum arquivo selecionado até o momento." readonly ' . $atributos . '></p>';
		$wp_media .= '<p><button type="button" class="button button-primary button-large selecionar-midia">';
		$wp_media .= '<span class="dashicons dashicons-upload"></span>';
		$wp_media .=  ' Selecionar ou enviar anexo';
		$wp_media .=  '</button></p>';
		return $wp_media .= '<input type="hidden" id="' . $nome_campo  . '_tamanho" name="' . $nome_campo  . '_tamanho" value="' . $valor["tamanho"] . '" readonly />';
	}

	/**
	 * Renderiza um campo de seleção.
	 *
	 * @param array 	$campo 		Array associativo contendo as informações do campo.
	 * @param mixed 	$valor 		Valor inicial do campo.
	 * @param string 	$atributos 	Atributos adicionais do campo.
	 * @param string 	$grupo_id  	ID do grupo do qual o campo faz parte.
	 * 
	 * @return string
	 */
	private function renderiza_campo_select(array $campo, $valor, string $atributos, ?string $grupo_id) {
		$nome_campo  = $this->adiciona_nome_grupo_campo($campo["name"], $grupo_id);
		$placeholder = !empty($campo['placeholder']) ? " placeholder='{$campo['placeholder']}'" : '';
		$select      = "<select name='{$nome_campo}' {$placeholder} {$atributos}'>";

		if (!isset($campo["desativar-opcao-padrao"])) {
			$select .= '<option value="" selected disabled>' . ($campo["placeholder"] ?? 'Selecione uma opção') . '</option>';
		}

		// Verificar e preparar as opções para o select
		if (is_string($campo["opcoes"]) && is_callable([$this, $campo["opcoes"]])) {
			$opcoes = call_user_func([$this, $campo["opcoes"]]);
		} elseif (is_array($campo["opcoes"]) && !empty($campo["opcoes"])) {
			$opcoes = $campo["opcoes"];
		} else {
			$opcoes = [];
		}

		if (is_array($opcoes) && !empty($opcoes)) {
			foreach ($opcoes as $opcao) {
				$option_attributes = $this->obtem_atributos_campo($opcao, '');
				$select .=  '<option value="' . htmlspecialchars($opcao["valor"]) . '" ' . selected($valor, $opcao["valor"], false) . $option_attributes . '>' . htmlspecialchars($opcao["texto"]) . '</option>';
			}
		}

		return $select .= '</select>';
	}

	/**
	 * Renderiza um grupo de campos.
	 *
	 * @param array 	$campo 				Array associativo contendo as informações do campo.
	 * @param array 	$meta_box_erros 	Array associativo contendo mensagens de erro.
	 * 
	 * @return string
	 */
	private function renderiza_campo_grupo($campo, $meta_box_erros) {
		$campos_grupo = '';

		foreach ($campo["campos"] as $subcampo) {
			$campos_grupo .= $this->renderiza_fieldset($subcampo, $meta_box_erros, $campo["name"]);
		}

		return $campos_grupo;
	}

	/**
	 * Valida se os campos relacionados existem no formulário ou contexto de validação.
	 *
	 * @param array $campos_relacionados Lista de campos para verificar.
	 * @return bool Retorna true se todos os campos existem, false caso contrário.
	 */
	private function valida_campos_relacionados($campos_relacionados) {
		foreach ($campos_relacionados as $campo_nome) {
			if (!array_key_exists($campo_nome, $_POST)) { // Verifica se o campo existe no POST
				return false;
			}
		}
		return true;
	}

	/**
	 * Obtém os rótulos dos campos baseados nos seus nomes.
	 * 
	 * Esta função percorre um array de todos os campos disponíveis e um array com os nomes de campos específicos,
	 * e retorna um array contendo os rótulos correspondentes a esses nomes. Isso é útil para converter nomes técnicos
	 * de campos em rótulos mais legíveis para o usuário.
	 *
	 * @param array $todos_os_campos Array contendo todos os campos disponíveis, cada campo como um array associativo
	 *                               que deve incluir pelo menos 'name' (nome do campo) e 'label' (rótulo do campo).
	 * @param array $nomes_de_campos Array contendo os nomes dos campos para os quais os rótulos são necessários.
	 * @return array Retorna um array de rótulos correspondentes aos nomes de campos fornecidos.
	 */
	private function obter_labels_por_nomes($todos_os_campos, $nomes_de_campos) {
		$labels = [];
		foreach ($nomes_de_campos as $nome) {
			foreach ($todos_os_campos as $campo) {
				if ($campo["name"] === $nome) {
					$labels[] = $campo["label"];
					break;  // Encerra o loop interno uma vez que o rótulo é encontrado
				}
			}
		}
		return $labels;
	}

	/**
	 * Registra as folhas de estilo.
	 * 
	 * Obtém a URL baseada na estrutura de plugins.
	 */
	public function enqueue_styles() {
		wp_enqueue_style('cbbox-style', plugin_dir_url(__DIR__) . 'css/style.css');
	}
}
