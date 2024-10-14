<?php

/**
 * Classe CBBox
 * 
 * Esta classe é responsável por criar e gerenciar formulários de maneira dinâmica e flexível.
 * Ela permite a adição de diversos tipos de campos, validações e estilizações personalizadas.
 *
 * @package charlesbatista/cbbox
 * @version 1.8.0
 * @author Charles Batista <charles.batista@tjce.jus.br>
 * @license MIT License
 * @url https://packagist.org/packages/charlesbatista/cbbox
 */
class CBBox extends CBBox_Helpers {

	/**
	 * Versão do framework
	 */
	private $versao = '1.8.0';

	/**
	 * Array com todas as meta boxes a serem montadas
	 * 
	 * @var array
	 */
	private array $meta_boxes;

	/**
	 * ID da página ou post type onde as meta boxes serão renderizadas.
	 * 
	 * @var string
	 */
	private string $pagina_id;

	/**
	 * Transient com erros de validação do formulário de cada meta box
	 */
	private mixed $meta_box_erros = null;

	/**
	 * Array com erros de validação do formulário como um todo
	 *
	 * @var array
	 */
	private array $meta_boxes_erros = [];

	private array $meta_boxes_erros_campos = [];

	/**
	 * Array com os formatos e os mime-types correspondentes.
	 * 
	 * Usados principalmente para permitir a escolha de formatos validos para os campos
	 * de anexo de arquivos.
	 *
	 * @var array
	 */
	private array $mime_types = [
		'pdf'   => 'application/pdf',
		'doc'   => 'application/msword',
		'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'   => 'application/vnd.ms-excel',
		'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'ppt'   => 'application/vnd.ms-powerpoint',
		'pptx'  => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'odt'   => 'application/vnd.oasis.opendocument.text',
		'ods'   => 'application/vnd.oasis.opendocument.spreadsheet',
		'odp'   => 'application/vnd.oasis.opendocument.presentation',
		'txt'   => 'text/plain',
		'rtf'   => 'application/rtf',
		'csv'   => 'text/csv',
		'html'  => 'text/html',
		'htm'   => 'text/html',
		'jpg'   => 'image/jpeg',
		'jpeg'  => 'image/jpeg',
		'png'   => 'image/png',
		'gif'   => 'image/gif',
		'bmp'   => 'image/bmp',
		'tif'   => 'image/tiff',
		'tiff'  => 'image/tiff',
		'zip'   => 'application/zip',
		'rar'   => 'application/x-rar-compressed',
		'7z'    => 'application/x-7z-compressed',
		'mp3'   => 'audio/mpeg',
		'wav'   => 'audio/x-wav',
		'mp4'   => 'video/mp4',
		'mov'   => 'video/quicktime',
		'wmv'   => 'video/x-ms-wmv',
		'flv'   => 'video/x-flv'
	];

	/**
	 * Construtor da classe.
	 * 
	 * @param string 	$pagina_id 		ID da página ou post type onde as meta boxes serão renderizadas.
	 * @param array 	$meta_boxes 	Array com todas as meta boxes a serem montadas.
	 */
	public function __construct(string $pagina_id, array $meta_boxes) {
		$this->pagina_id  = $pagina_id;
		$this->meta_boxes = $meta_boxes;

		if (is_admin()) {
			add_action('add_meta_boxes', [$this, 'gera_meta_boxes'], 10, 2);
			add_action("save_post_{$this->pagina_id}", [$this, 'salva_valores_meta_boxes']);
			add_filter("wp_insert_post_data", [$this, 'ajusta_status_post'], 10, 3);

			add_action('admin_enqueue_scripts', [$this, 'enqueue_styles']);
			add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
		}
	}

	/**
	 * Com base no array de meta boxes definidos, carrega os dados e adiciona
	 * as meta boxes no contexto do WordPress.
	 *
	 * @return void
	 */
	public function gera_meta_boxes() {
		// itera sobre o array de meta boxes para criar cada meta box
		foreach ($this->meta_boxes as $meta_box) {
			$this->adiciona_metabox($meta_box["id"], $meta_box["titulo"], 'meta_box', $this->pagina_id, [
				'campos'  => $meta_box["campos"]
			]);
		}
	}

	/**
	 * Salva os valores dos campos personalizados especificados em meta boxes após validação.
	 *
	 * Este método itera sobre todas as meta boxes definidas, valida cada uma usando um nonce
	 * e outros critérios de validação de campos, e salva os dados validados no banco de dados.
	 * Caso ocorra algum erro durante a validação, registra o erro e redireciona para a página de edição.
	 *
	 * @param int 	$post_id 	O ID do post sendo salvo, usado para associar os metadados salvos.
	 * 
	 * Fluxo:
	 * - Verifica se a requisição é válida (não é autosave, ajax, etc.).
	 * - Itera sobre cada meta box, verificando a validade do nonce e dos campos.
	 * - Se um erro é encontrado, adiciona ao registro de erros e pula para a próxima meta box.
	 * - Se não houver erros, salva os dados dos campos usando uma função callback.
	 * - Se erros foram registrados, redireciona para a página de edição com os erros como query params.
	 */
	public function salva_valores_meta_boxes(int $post_id) {
		// Primeiro, verifica se a requisição atual é válida.
		// Isso impede ações desnecessárias durante autosaves ou requisições AJAX.
		if ($this->verifica_requisicao_valida() === false) {
			return;  // Se a requisição não for válida, o método é encerrado prematuramente.
		}

		// Itera sobre cada meta box registrada para este post.
		foreach ($this->meta_boxes as &$meta_box) {
			// Verifica se o nonce é válido e se os campos personalizados são válidos.
			// O nonce ajuda a proteger contra ataques CSRF garantindo que a requisição veio do site e do usuário esperado.
			if (!$this->verifica_nonce_valido($meta_box["id"], $post_id)) {
				$this->meta_boxes_erros[] = "nonce_invalido";  // Adiciona erro de nonce inválido ao registro de erros.
			} else {
				// Se o nonce é válido, procede para validar os campos personalizados.
				if (!$this->valida_campos_personalizados($post_id, $meta_box["id"], $meta_box["campos"])) {
					$this->meta_boxes_erros[] = "erro_de_validacao";  // Adiciona erro de validação de campos ao registro de erros.
				} else {
					// Se não houver erro de validação dos campos, salva os valores dos campos personalizados.
					call_user_func([$this, 'salva_valores'], $post_id, $meta_box["id"], $meta_box["campos"]);
				}
			}
		}

		// Se houver erros após processar todas as meta boxes, 
		// redireciona o usuário de volta para a página de edição do post.
		add_action('shutdown', function () use ($post_id) {
			if (!empty($this->meta_boxes_erros)) {
				// Constrói uma query string com os erros para anexar à URL de redirecionamento.
				$erro_query = http_build_query(['erro' => implode(',', array_unique($this->meta_boxes_erros))]);

				// Redireciona para a página de edição do post com os erros como parâmetros GET.
				wp_redirect(admin_url("post.php?post={$post_id}&action=edit&" . $erro_query));
				exit(); // Encerra a execução para garantir que o redirecionamento ocorra.
			}
		});
	}

	/**
	 * Ajusta o status do post baseado na validação de todos os campos de meta boxes antes de salvar no banco de dados.
	 *
	 * Este método é chamado no filtro `wp_insert_post_data` e determina se o post deve ser publicado ou revertido para rascunho
	 * com base na validação dos campos personalizados de todas as meta boxes associadas ao tipo de post 'licitantes-sancoes'.
	 *
	 * @param array 	$data 		Array de dados do post que está prestes a ser salvo no banco de dados.
	 * @param array 	$postarr 	Array contendo informações do post, incluindo ID e outros dados relevantes.
	 * @return array 				O array de dados modificado com o status do post ajustado conforme a validação.
	 * 
	 * Fluxo:
	 * - Verifica se a requisição é válida.
	 * - Verifica se o tipo de post é da seção correta.
	 * - Valida cada meta box: se qualquer uma falhar na validação, o post é definido como 'draft'.
	 * - Se todas as validações passarem, o post é definido como 'publish'.
	 */
	public function ajusta_status_post($data, $postarr, $unsanitized_postarr) {
		// Verifica se a requisição é válida para evitar processar em situações não desejadas.
		if ($this->verifica_requisicao_valida() === false) {
			return $data;  // Retorna os dados sem alterações se a requisição não for válida.
		}

		// Verifica se o tipo de post é o esperado para esta validação.
		if ($data['post_type'] === $this->pagina_id) {
			$validado = true;

			// Itera sobre cada meta box para verificar sua validade.
			foreach ($this->meta_boxes as &$meta_box) {
				// Verifica o nonce e valida os campos. 
				// Se falhar, interrompe a validação e ajusta o flag.
				if (!$this->verifica_nonce_valido($meta_box["id"], $postarr["ID"]) || !$this->valida_campos_personalizados($postarr["ID"], $meta_box["id"], $meta_box["campos"])) {
					$validado = false;
					break; // Interrompe o loop ao encontrar o primeiro erro.
				}
			}

			// Define o status do post baseado na validação.
			if (!$validado) {
				$data['post_status'] = 'draft';  // Se houver erro, o post vai para rascunho.
			}
		}

		return $data;  // Retorna os dados ajustados.
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
		// Atribui um timestamp como ID do post se o ID original for nulo, garantindo um identificador único.
		$post_id = $post_id ?? time();

		// Chama o método recursivo para validar todos os campos e subcampos.
		$this->valida_campos_recursivamente($post_id, $campos);

		// Verifica e salva os erros encontrados, retornando o resultado da operação.
		return $this->verificar_e_salvar_erros($post_id, $meta_box_id);
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
	 * @param string 	$prefixo 	Prefixo acumulado para nomes de campos dentro de grupos, usado para formar o nome completo do campo.
	 */
	private function valida_campos_recursivamente(int $post_id, array &$campos, $prefixo = '') {
		if (!empty($campos)) {
			foreach ($campos as $campo) {
				if (!empty($campo["name"])) {
					if ($campo["tipo"] === 'wp_media') {
						$campo_nome_completo = $prefixo . $campo["name"] . "_url";
					} else {
						$campo_nome_completo = $prefixo . $campo["name"];
					}

					// recebe o valor do formulário
					$valor = $_POST[$campo_nome_completo] ?? null;

					// salva o valor do campo num transient para permanecer o valor 
					// que o usuário enviou caso haja falha na validação.
					set_transient(join('_', [$post_id, $campo_nome_completo]), $valor, 60);

					// Verifica se o campo é obrigatório e se está vazio
					if (!empty($campo["atributos"]["required"]) && empty($valor)) {
						$this->meta_boxes_erros_campos[$campo_nome_completo] = $campo["label"] . ' é um campo obrigatório.';
					}

					// Processamento de validações específicas
					if (!empty($campo["validacao"]) && is_array($campo["validacao"])) {
						$this->aplica_validacoes($post_id, $campos, $campo, $valor, $campo_nome_completo);
					}

					// Se o campo é um grupo, recursivamente valida seus subcampos
					if (isset($campo['tipo']) && $campo['tipo'] === 'grupo' && isset($campo['campos'])) {
						$novo_prefixo = $campo_nome_completo . '_';
						$this->valida_campos_recursivamente($post_id, $campo['campos'], $novo_prefixo);
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
	 * @param string 	$campo_nome_completo 	Nome completo do campo, incluindo prefixos de grupos para identificação única.
	 */
	private function aplica_validacoes(int $post_id, array &$campos, array $campo, $valor, $campo_nome_completo) {
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
						$this->meta_boxes_erros_campos[$campo_nome_completo] = 'O CPF informado é inválido.';
					}
					break;

				case 'cnpj':
					if (!empty($valor) && !$this->valida_cnpj($valor)) {
						$this->meta_boxes_erros_campos[$campo_nome_completo] = 'O CNPJ informado é inválido.';
					}
					break;

				case 'data':
					// Define um formato padrão para as datas caso um não seja especificado
					$formato = !isset($parametros) ? 'd/m/Y' : $parametros;

					if (!empty($valor) && !$this->valida_data($valor, $formato)) {
						$this->meta_boxes_erros_campos[$campo_nome_completo] = 'A data fornecida é inválida ou não está no formato esperado (' . $formato . ').';
					}
					break;

				case 'ate_hoje':
					// Define um formato padrão para as datas caso um não seja especificado
					$formato = !isset($parametros) ? 'd/m/Y' : $parametros;

					if (!empty($valor) && $this->data_maior_que_hoje($valor, $formato)) {
						$this->meta_boxes_erros_campos[$campo_nome_completo] = 'A data não pode ser maior que a data de hoje.';
					}
					break;

				case 'unico':
					if (!empty($parametros)) {
						$campos_relacionados = explode(',', $parametros);

						// verifica se realmente existem múltiplos campos e se a validação é necessária
						if (count($campos_relacionados) > 1) {
							if (!$this->valida_campos_relacionados($campos_relacionados)) {
								$labels_relacionados  = $this->obtem_labels_por_nomes($campos, $campos_relacionados);
								$texto_erro_valicadao = 'Apenas um dos campos (' . join(', ', $labels_relacionados) . ') pode ser preenchido.';

								// aplica o erro a todos os campos relacionados que estão preenchidos
								foreach ($campos_relacionados as $campo_relacionado) {
									if (!empty($_POST[$campo_relacionado])) {
										$this->meta_boxes_erros_campos[$campo_relacionado] = $texto_erro_valicadao;
									}
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
						$this->meta_boxes_erros_campos[$campo_nome_completo] = 'A URL fornecida é inválida.';
					}
					break;

				case 'maior_ou_igual':
					$this->verifica_maior_ou_igual($post_id, $campos, $valor, $parametros, $campo_nome_completo, $erros);
					break;

				case 'obrigatorio_se_vazio':
					$campo_relacionado = $parametros;
					if (empty($valor) && empty($_POST[$campo_relacionado])) {
						$campos_relacionados       = [$campo_nome_completo, $campo_relacionado];
						$label_campos_relacionados = $this->obtem_labels_por_nomes($campos, $campos_relacionados);
						$texto_erro_valicadao      = 'Pelo menos um dos campos (' . join(', ', $label_campos_relacionados) . ') deve ser preenchido.';

						// aplica o erro a todos os campos relacionados
						foreach ($campos_relacionados as $campo_relacionado) {
							$this->meta_boxes_erros_campos[$campo_relacionado] = $texto_erro_valicadao;
						}
					}
					break;
			}
		}
	}

	/**
	 * Verifica se o valor de um campo é maior ou igual a um valor específico ou ao valor de outro campo.
	 *
	 * @param int 		$post_id 				O ID do post que está sendo validado, usado para gerenciar valores temporários.
	 * @param array 	&$campos 				Referência do array de todos os campos.
	 * @param mixed 	$valor 					Valor atual do campo que está sendo validado.
	 * @param mixed 	$parametro_comparacao 	Pode ser o nome de outro campo ou um valor fixo para comparação.
	 * @param string 	$campo_nome_completo 	Nome completo do campo para identificação em mensagens de erro.
	 * @return array 							Retorna um array de erros, possivelmente vazio se não houver erros.
	 */
	protected function verifica_maior_ou_igual(int $post_id, array &$campos, mixed $valor, mixed $parametro_comparacao, string $campo_nome_completo) {
		// Verifica se o valor é null ou uma string vazia
		if ($valor === null || $valor === '') {
			return;
		}

		// obtém os nomes de todos os campos
		$nomes_campos = array_column($campos, 'name');

		// Verifica se o parâmetro de comparação é um valor ou um campo.
		$indice = array_search($parametro_comparacao, $nomes_campos);
		if ($indice !== false) {
			// O parâmetro é um nome de campo, obtém o valor do campo correspondente
			$valor_campo_comparado = get_transient(join('_', [$post_id, $parametro_comparacao]));

			// verifica se o valor de comparação e o valor do campo são datas.
			if ($this->valida_data($valor) && $this->valida_data($valor_campo_comparado)) {
				// Se datas, vamos compará-las
				if ($this->compara_datas($valor, $valor_campo_comparado) === -1) {
					$this->meta_boxes_erros_campos[$campo_nome_completo] = 'O valor deve ser maior ou igual ao valor do campo <b>' . $campos[$indice]["label"] . '</b>: ' . $valor_campo_comparado . '.';
				}
			} elseif (is_numeric($valor) && is_numeric($valor_campo_comparado)) {
				// Se números, vamos compará-los
				if ($valor < $valor_campo_comparado) {
					$this->meta_boxes_erros_campos[$campo_nome_completo] = 'O valor deve ser maior ou igual ao valor do campo <b>' . $campos[$indice]["label"] . '</b>: ' . $valor_campo_comparado . '.';
				}
			} else {
				$this->meta_boxes_erros_campos[$campo_nome_completo] = 'Os valores a serem comparados devem ser do mesmo tipo (data ou número).';
			}
		} else {
			// Não encontrou o nome do campo, considera que é um valor fixo.
			if ($this->valida_data($valor) && $this->valida_data($parametro_comparacao)) {
				// Se datas, vamos compará-las
				if ($this->compara_datas($valor, $parametro_comparacao) === -1) {
					$this->meta_boxes_erros_campos[$campo_nome_completo] = 'O valor deve ser maior ou igual a ' . $parametro_comparacao . '.';
				}
			} elseif (is_numeric($valor) && is_numeric($parametro_comparacao)) {
				// Se números, vamos compará-los
				if ($valor < $parametro_comparacao) {
					$this->meta_boxes_erros_campos[$campo_nome_completo] = 'O valor deve ser maior ou igual a ' . $parametro_comparacao . '.';
				}
			} else {
				$this->meta_boxes_erros_campos[$campo_nome_completo] = 'Os valores a serem comparados devem ser do mesmo tipo (data ou número).';
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
	 * @param string 	$meta_box_id 	O ID do meta box relacionado, utilizado para construir o identificador único do transient.
	 * @return bool 					Retorna false se erros foram encontrados e salvos no transient, ou true se nenhum erro foi encontrado, indicando que o processo pode continuar.
	 */
	protected function verificar_e_salvar_erros($post_id, $meta_box_id) {
		if (!empty($this->meta_boxes_erros_campos)) {
			$transient = join('_', [$meta_box_id, $post_id]);
			set_transient($transient, $this->meta_boxes_erros_campos, 60); // Dura 60 segundos
			return false;
		}

		return true;
	}

	/**
	 * Adiciona um erro de validação a um campo específico de um meta box.
	 *
	 * @param string $campo_nome
	 * @param string $mensagem
	 * @return void
	 */
	public function adicionar_mensagem_erro(string $campo_nome, string $mensagem) {
		$this->meta_boxes_erros_campos[$campo_nome] = $mensagem;
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
		$this->renderiza($meta_box_id, $post->ID, $meta_box["args"]["campos"]);
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
		if ($campo["tipo"] === 'wp_media') {
			$subcampos = ['url', 'id'];

			foreach ($subcampos as $subcampo) {
				$nome_campo  = $prefixo_nome_campo . $campo["name"] . "_" . $subcampo;
				$valor_campo = $this->formata_valor_campo($campo, 'salvar', $nome_campo);
				$this->mantem_valor_bd($post_id, $nome_campo, $valor_campo);
			}
		} else {
			$campo_nome_completo = $prefixo_nome_campo . $campo["name"];
		}

		// verificamos se existe alguma configuração para formatar o formato de exibição
		$valor_campo = $this->formata_valor_campo($campo, 'salvar', $campo_nome_completo);

		$this->mantem_valor_bd($post_id, $campo_nome_completo, $valor_campo);
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
		// se um nome foi configurado para o campo
		if (isset($campo['name'])) {
			// vamos acrescentar o prefixo ao campo do nome, para casos
			// em que o campo faz parte de um grupo de campos.
			$nome_completo = $prefixo_grupo . $campo['name'];

			// se o tipo do campo for para envio de arquivos pelo Mídias do WordPress
			if ($campo['tipo'] === 'wp_media') {
				$campo['valor'] = [
					'url' => $post_meta[$nome_completo . '_url'][0] ?? null,
					'id'  => $post_meta[$nome_completo . '_id'][0] ?? null
				];
			} else {
				// definimos o nome do transient que guarda o valor do campo enviado via formulário
				$transient       = join('_', [$post_id, $nome_completo]);
				$valor_transient = get_transient($transient);

				// se ele existir, vamos definir o valor do campo como esse valor
				if ($valor_transient !== false) {
					$campo['valor'] = $valor_transient;

					// se não existir, vamos pegar o valor do banco de dados mesmo
				} else {
					$campo['valor'] = $post_meta[$nome_completo][0] ?? $campo["valor"] ?? null;

					// verificamos se existe alguma configuração para formatar o formato de exibição
					$this->formata_valor_campo($campo, 'exibir');
				}

				// uma vez que já populamos o campo, vamos deletar o transient para evitar conflitos futuros
				delete_transient($transient);
			}
		}
	}

	/**
	 * Formata o valor do campo para exibição tem tela.
	 * 
	 * @param array 		&$campo						Referência do campo.
	 * @param string 		$tipo					Tipo da formatação: "salvar" ou "exibir".
	 * @param string|null	$nome_campo_completo	Caso faça parte de um grupo, esse é o nome completo.
	 * 
	 * @return null|string 	$valor_campo			Retorna nulo se o valor do campo for vazio, ou valor formatado se tiver sido configurado, ou valor original.
	 */
	private function formata_valor_campo(array &$campo, string $tipo, string|null $nome_campo_completo = null) {
		// se um valor para o campo for pré-definido, vamos populá-lo com este valor.
		if (!empty($campo['valor'])) {
			$valor_campo = $campo['valor'];
		}

		// se um novo valor foi enviado via $_POST para o campo, vamos populá-lo com este valor. 
		// podendo substituir o valor pré-definido.
		if (!empty($_POST[$nome_campo_completo])) {
			$valor_campo = sanitize_text_field($_POST[$nome_campo_completo]);
		}

		// se mesmo assim o valor do campo for vazio, não precisamos formatar nada
		// e apenas devolvemos "null"
		if (empty($valor_campo)) {
			return null;
		}

		// se nenhuma configuração para formatar o valor do campo tiver sido definida
		// também não faremos nada e apenas devolvemos o valor original.
		if (empty($campo['formatos'][$tipo])) {
			return $valor_campo;
		}

		// salvamos o formato em uma variável para facilitar o código
		$formato = $campo['formatos'][$tipo];

		switch (true) {
			case is_string($formato) && strpos($formato, 'regex:') === 0:
				// Extraímos a regex a partir do padrão especificado e aplicamos
				$regex       = substr($formato, 6);
				$valor_campo = preg_replace($regex, '', $valor_campo);
				break;
			case is_string($formato) && strpos($formato, 'data:') === 0:
				// Formatação de data conforme especificado
				$formato_exibir = substr($formato, 5);
				$formato_de     = $this->determinar_formato_origem($formato_exibir);
				if ($formato_de) {
					$valor_campo = $this->formata_data($valor_campo, $formato_de, $formato_exibir);
				}
				break;
			case is_string($formato) && $formato === 'apenas_numeros':
				// Remove todos os caracteres não numéricos
				$valor_campo = preg_replace('/\D/', '', $valor_campo);
				break;
			case (is_array($formato) && isset($formato['decimal'])) || (is_string($formato) && $formato === 'decimal'):
				$valor_campo = $this->formata_decimal($valor_campo, $formato['decimal'] ?? []);
				break;

			default:
				// Se nenhum formato conhecido foi especificado, não altera o valor
				return $valor_campo;
		}

		// atualizamos o valor na referência do campo
		$campo['valor'] = $valor_campo;

		// retorna o valor do campo formatado
		return $valor_campo;
	}

	/**
	 * Determina o formato de origem com base no formato de destino
	 *
	 * @param string $formato_exibir Formato de exibição configurado
	 * @return string|null
	 */
	private function determinar_formato_origem($formato_exibir) {
		switch ($formato_exibir) {
			case 'd/m/Y':
				return 'Y-m-d';
			case 'Y-m-d':
				return 'd/m/Y';
			default:
				return null;  // Formato desconhecido
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
	 * Verifica a validade do nonce enviado via POST para uma operação de meta box específica.
	 *
	 * Este método é usado para garantir que a requisição ao servidor foi intencionada pelo usuário
	 * e veio de uma fonte confiável dentro da mesma sessão, prevenindo ataques CSRF.
	 *
	 * @param string $meta_box_id 	O identificador da meta box, usado para construir o nome do nonce.
	 * @param int $post_id 			O ID do post que está sendo editado, usado para garantir unicidade do nonce.
	 * @return bool 				Retorna verdadeiro se o nonce é válido e presente na requisição, falso caso contrário.
	 * 
	 * Uso:
	 * Se este método retornar falso, a operação que depende da validação do nonce
	 * deverá ser abortada para manter a segurança da aplicação.
	 */
	private function verifica_nonce_valido(string $meta_box_id, int $post_id) {
		// Verifica se a ação é de restauração de um post
		// Se sim, não precisamos validar o nonce.
		if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'restore') {
			return true;
		}

		// Verifica se o "nonce" de validação da requisição existe.
		if (!isset($_POST[$meta_box_id . '_nonce'])) {
			return false;
		}

		// Verifica se o nonce é válido.
		if (!wp_verify_nonce($_POST[$meta_box_id . '_nonce'], $meta_box_id . '_nonce')) {
			return false;
		}

		return true;
	}

	/**
	 * Verifica se a requisição atual pode proceder em termos de contexto de execução,
	 * evitando execuções indesejadas durante autosaves, requisições AJAX ou ao mover posts para a lixeira.
	 *
	 * Este método é utilizado para prevenir a execução de lógicas de processamento de formulários
	 * em contextos onde os dados não devem ser alterados, como durante salvamentos automáticos pelo WordPress
	 * ou operações via AJAX que não estão diretamente relacionadas ao salvamento de posts.
	 *
	 * @return bool Retorna verdadeiro se a requisição é válida e deve ser processada, falso caso contrário.
	 * 
	 * Detalhes:
	 * - DOING_AUTOSAVE: Verifica se a operação é um autosave, comum quando o WordPress salva automaticamente um rascunho.
	 * - DOING_AJAX: Verifica se a requisição veio via AJAX, útil para ignorar processamentos em chamadas AJAX que não necessitam salvar dados.
	 * - action 'trash' ou 'untrash': Ignora a execução quando o post está sendo movido para a lixeira ou restaurado dela.
	 */
	private function verifica_requisicao_valida() {
		// Verifica se está sendo realizado um autosave.
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return false;
		}

		// Verifica se a requisição é AJAX.
		if (defined('DOING_AJAX') && DOING_AJAX) {
			return false;
		}

		// Verifica se a ação é enviar para lixeira ou retirar.
		if (isset($_REQUEST['action']) && ($_REQUEST['action'] === 'trash' || $_REQUEST['action'] === 'untrash')) {
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
	public function adiciona_metabox(string $meta_box_id, string $titulo, string $callback = 'meta_box', string $post_type = 'post', array $callback_args = []) {
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
		$this->meta_box_erros = get_transient(join('_', [$meta_box_id, $post_id]));

		// exibe mensagem de erro caso o nonce esteja inválido
		$this->exibe_mensagem_erro_nonce_invalido();

		// exibe mensagens de erro de validação dos campos
		$this->exibe_mensagem_erro();

		// popula os campos com os dados do banco de dados
		$campos = $this->popula_valores_campos($post_id, $campos);

		// itera sobre todos os campos para renderizá-los
		foreach ($campos as $campo) {
			$form .= $this->renderiza_campo($campo);
		}

		// deleta o transient
		delete_transient(join('_', [$meta_box_id, $post_id]));

		$form .= '</tbody>';
		echo $form .= '</table>';
	}

	/**
	 * Exibe uma mensagem de erro única se houver erros nos meta boxes e a mensagem ainda não tiver sido exibida.
	 *
	 * Utiliza uma variável estática para garantir que a mensagem de erro seja exibida apenas uma vez
	 * durante a execução do script, evitando redundâncias na interface do usuário.
	 *
	 *                              A função verifica se este array não está vazio para proceder com a exibição da mensagem.
	 */
	function exibe_mensagem_erro() {
		// Variável estática para rastrear se a mensagem de erro já foi exibida
		static $mensagem_erro_form_exibida = false;

		// Verifica se há erros e se a mensagem ainda não foi exibida
		if (!empty($this->meta_box_erros) && !$mensagem_erro_form_exibida) {
			echo '<div class="notice notice-error"><p>';
			echo 'Post marcado como <b>Rascunho</b> pois foram encontrados erros no formulário! Verifique-os antes de salvar novamente.';
			echo '</p></div>';
			$mensagem_erro_form_exibida = true;  // Marca que a mensagem foi exibida, para evitar repetição
		}
	}

	/**
	 * Exibe uma mensagem de erro se um nonce inválido for detectado.
	 * 
	 * Este método verifica se o erro 'nonce_invalido' foi passado via URL e exibe uma
	 * mensagem de erro única se esse for o caso. Utiliza uma variável estática para garantir
	 * que a mensagem seja exibida apenas uma vez por ciclo de requisição.
	 */
	function exibe_mensagem_erro_nonce_invalido() {
		static $mensagem_erro_nonce_invalido_exibida = false;

		// Verifica se o erro de 'nonce_invalido' está presente na URL e se a mensagem ainda não foi exibida
		if (isset($_GET['erro']) && strpos($_GET['erro'], 'nonce_invalido') !== false && !$mensagem_erro_nonce_invalido_exibida) {
			echo '<div class="notice notice-warning"><p>';
			echo 'Houve um erro ao processar sua solicitação. Isso pode ocorrer se a página ficar aberta por muito tempo. Por favor, tente novamente.';
			echo '</p></div>';
			$mensagem_erro_nonce_invalido_exibida = true;
		}
	}

	/**
	 * Renderiza a área que compõe o fieldset de um campo ou grupo de campos.
	 *
	 * @param array $campo 				Array associativo contendo as informações do campo.
	 * 
	 * @return void
	 */
	private function renderiza_campo($campo) {
		// verifica se o campo é obrigatório e adiciona o "*" na frente:
		$label_obrigatorio = isset($campo["atributos"]["required"]) ? '*' : '';

		$area_campo = '<tr class="campo-formulario ' . $campo["tipo"] . '">';
		$area_campo .= '<th scope="row">' . $campo["label"] . $label_obrigatorio . ':</th>';
		$area_campo .= '<td>';
		$area_campo .= $this->renderiza_fieldset($campo);
		$area_campo .= '</td>';
		return $area_campo .= '</tr>';
	}

	/**
	 * Renderiza um único campo do formulário.
	 *
	 * @param array $campo 				Array associativo contendo as informações do campo.
	 * @param int $grupo_id  		 	ID do grupo do qual o campo faz parte.
	 * 
	 * @return string
	 */
	private function renderiza_fieldset($campo, $grupo_id = null) {
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
				$fieldset .= $this->renderiza_campo_grupo($campo);
				break;
		}

		// exibimos uma descrição para o campo caso tenha sido configurada
		if (isset($campo["descricao"])) {
			$fieldset .=  '<p class="descricao">' . $campo["descricao"] . '</p>';
		}

		// quando um campo for "wp_media", insere o sufixo relacionado ao tipo de enviar mídia
		if ($campo["tipo"] === 'wp_media') {
			$campo["name"] = $campo["name"] . "_url";
		}

		// exibimos o erro do campo caso não tenha passado na validação
		$nome_campo_erros = (isset($grupo_id) ? $grupo_id . '_' : null) . $campo["name"];

		if (isset($this->meta_box_erros[$nome_campo_erros])) {
			$fieldset .=  '<p class="mensagem erro"><span class="dashicons dashicons-no"></span> ' . $this->meta_box_erros[$nome_campo_erros] . '</p>';
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
	private function renderiza_campo_textarea($campo, $valor = "", $atributos = "", ?string $grupo_id = "") {
		$nome_campo = $this->adiciona_nome_grupo_campo($campo["name"], $grupo_id);
		return '<textarea id="' . $nome_campo . '" name="' . $nome_campo . '" ' . $atributos . '>' . htmlentities($valor ?? "") . '</textarea>';
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

		if (!empty($campo["formatos"]) && is_array($campo["formatos"])) {
			$mime_types = array_map(function ($formato) {
				return $this->mime_types[$formato];
			}, $campo["formatos"]);

			$data_formatos_validos = ' data-formatos-validos="' . implode(', ', $mime_types) . '"';
		} else {
			$data_formatos_validos = "";
		}

		$wp_media = '<p><input type="text" id="' . $nome_campo . '_url" name="' . $nome_campo  . '_url" value="' . $valor["url"] . '" placeholder="Nenhum arquivo selecionado até o momento." readonly ' . $atributos . '></p>';
		$wp_media .= '<p><button type="button" class="button button-primary button-large cbbox-selecionar-midia"' . $data_formatos_validos . '>';
		$wp_media .= '<span class="dashicons dashicons-upload"></span>';
		$wp_media .=  ' Selecionar ou enviar anexo';
		$wp_media .=  '</button></p>';
		return $wp_media .= '<input type="hidden" id="' . $nome_campo  . '_id" name="' . $nome_campo  . '_id" value="' . $valor["id"] . '" readonly />';
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
			if (count($opcoes) === 1 && isset($campo["pre-selecionar-opcao-unica"]) && $campo["pre-selecionar-opcao-unica"]) {
				// Define $valor como a única opção disponível
				$valor = is_array(reset($opcoes)) ? reset($opcoes)['valor'] : key($opcoes);
			}

			foreach ($opcoes as $key => $opcao) {
				// Verifica se é um array associativo ou indexado
				if (is_array($opcao)) {
					// Array de arrays (ex: com 'valor' e 'texto')
					$option_attributes = $this->obtem_atributos_campo($opcao, '');
					$select .= '<option value="' . htmlspecialchars($opcao['valor']) . '" ' .
						selected($valor, $opcao['valor'], false) . $option_attributes . '>' .
						htmlspecialchars($opcao['texto']) . '</option>';
				} else {
					// Array associativo simples (ex: chave como 'valor' e 'texto')
					$select .= '<option value="' . htmlspecialchars($key) . '" ' .
						selected($valor, $key, false) . '>' .
						htmlspecialchars($opcao) . '</option>';
				}
			}
		}

		return $select .= '</select>';
	}

	/**
	 * Renderiza um grupo de campos.
	 *
	 * @param array $campo Array associativo contendo as informações do campo.
	 * 
	 * @return string
	 */
	private function renderiza_campo_grupo($campo) {
		$campos_grupo = '';

		foreach ($campo["campos"] as $subcampo) {
			$campos_grupo .= $this->renderiza_fieldset($subcampo, $campo["name"]);
		}

		return $campos_grupo;
	}

	/**
	 * Valida se apenas um dos campos relacionados está preenchido.
	 *
	 * @param array $campos_relacionados Lista de campos para verificar.
	 * @return bool Retorna true se apenas um dos campos está preenchido, false caso contrário.
	 */
	private function valida_campos_relacionados(array $campos_relacionados) {
		$contador_preenchidos = 0; // Contador para campos preenchidos

		foreach ($campos_relacionados as $campo_nome) {
			if (!empty($_POST[$campo_nome]) && trim($_POST[$campo_nome]) !== '') {
				$contador_preenchidos++;
			}
		}

		// Retorna true se exatamente um dos campos estiver preenchido
		return $contador_preenchidos === 1;
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
	private function obtem_labels_por_nomes(array $todos_os_campos, array $nomes_de_campos) {
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
		if ($this->se_tela_plugin()) {
			wp_enqueue_style('cbbox-style', $this->obtem_url_completa_assets('css/style.css'));
		}
	}

	/**
	 * Registra os scripts.
	 * 
	 * Obtém a URL baseada na estrutura de plugins.
	 */
	public function enqueue_scripts() {
		if ($this->se_tela_plugin()) {
			wp_enqueue_script('cbbox-main-script', $this->obtem_url_completa_assets('js/main.js'), array(), $this->versao, true);
			wp_deregister_script('autosave');
		}
	}

	/**
	 * Verifica se a tela atual é a tela da seção do admin do plugin
	 */
	private function se_tela_plugin() {
		return is_admin() && function_exists('get_current_screen') && get_current_screen()->post_type === $this->pagina_id;
	}

	/**
	 * Obtém a URL completa para carregar assets;
	 *
	 * @param string 	$asset	Caminho relativo para o arquivo.
	 * @return string 			Caminho completo para o asset.
	 */
	private function obtem_url_completa_assets(string $asset) {
		// obtém o caminho absoluto do root do WordPress, substuindo as
		// barras para construção da URL.
		$caminho_absoluto_wordpress  = str_replace('\\', '/', ABSPATH);

		// obtém o caminho absoluto do diretório pai do diretório atual
		$caminho_absoluto_diretorio_principal = str_replace('\\', '/', dirname(__DIR__));

		// remove o caminho absoluto para obter apenas o caminho relativo do diretório
		$caminho_relativo_diretorio_principal = str_replace($caminho_absoluto_wordpress, '', $caminho_absoluto_diretorio_principal);

		// devolve a URL completa com a home do WordPress e caminho relativo do asset.
		return site_url($caminho_relativo_diretorio_principal . '/' . $asset);
	}
}
