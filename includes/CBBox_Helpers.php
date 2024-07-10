<?php

/**
 * Classe helpers com funções comuns ao admin e public.
 * 
 * @author Charles Batista <charles.batista@tjce.jus.br>
 */
class CBBox_Helpers {

	/**
	 * Obtém uma lista dos estados brasileiros.
	 *
	 * Este método retorna uma lista estática dos estados do Brasil, cada um representado como um array associativo
	 * que inclui a sigla do estado ('valor'), o nome completo do estado ('texto') e um 'slug' amigável para URLs.
	 *
	 * @return array Uma lista de arrays associativos, cada um contendo as chaves 'valor', 'texto', e 'slug'.
	 */
	protected function obtem_lista_estados() {
		$estados = [
			['valor' => 'AC', 'texto' => 'AC - Acre', 'slug' => 'acre'],
			['valor' => 'AL', 'texto' => 'AL - Alagoas', 'slug' => 'alagoas'],
			['valor' => 'AP', 'texto' => 'AP - Amapá', 'slug' => 'amapa'],
			['valor' => 'AM', 'texto' => 'AM - Amazonas', 'slug' => 'amazonas'],
			['valor' => 'BA', 'texto' => 'BA - Bahia', 'slug' => 'bahia'],
			['valor' => 'CE', 'texto' => 'CE - Ceará', 'slug' => 'ceara'],
			['valor' => 'DF', 'texto' => 'DF - Distrito Federal', 'slug' => 'distrito-federal'],
			['valor' => 'ES', 'texto' => 'ES - Espírito Santo', 'slug' => 'espirito-santo'],
			['valor' => 'GO', 'texto' => 'GO - Goiás', 'slug' => 'goias'],
			['valor' => 'MA', 'texto' => 'MA - Maranhão', 'slug' => 'maranhao'],
			['valor' => 'MT', 'texto' => 'MT - Mato Grosso', 'slug' => 'mato-grosso'],
			['valor' => 'MS', 'texto' => 'MS - Mato Grosso do Sul', 'slug' => 'mato-grosso-do-sul'],
			['valor' => 'MG', 'texto' => 'MG - Minas Gerais', 'slug' => 'minas-gerais'],
			['valor' => 'PA', 'texto' => 'PA - Pará', 'slug' => 'para'],
			['valor' => 'PB', 'texto' => 'PB - Paraíba', 'slug' => 'paraiba'],
			['valor' => 'PR', 'texto' => 'PR - Paraná', 'slug' => 'parana'],
			['valor' => 'PE', 'texto' => 'PE - Pernambuco', 'slug' => 'pernambuco'],
			['valor' => 'PI', 'texto' => 'PI - Piauí', 'slug' => 'piaui'],
			['valor' => 'RJ', 'texto' => 'RJ - Rio de Janeiro', 'slug' => 'rio-de-janeiro'],
			['valor' => 'RN', 'texto' => 'RN - Rio Grande do Norte', 'slug' => 'rio-grande-do-norte'],
			['valor' => 'RS', 'texto' => 'RS - Rio Grande do Sul', 'slug' => 'rio-grande-do-sul'],
			['valor' => 'RO', 'texto' => 'RO - Rondônia', 'slug' => 'rondonia'],
			['valor' => 'RR', 'texto' => 'RR - Roraima', 'slug' => 'roraima'],
			['valor' => 'SC', 'texto' => 'SC - Santa Catarina', 'slug' => 'santa-catarina'],
			['valor' => 'SP', 'texto' => 'SP - São Paulo', 'slug' => 'sao-paulo'],
			['valor' => 'SE', 'texto' => 'SE - Sergipe', 'slug' => 'sergipe'],
			['valor' => 'TO', 'texto' => 'TO - Tocantins', 'slug' => 'tocantins']
		];

		return $estados;
	}

	/**
	 * Converte uma data de um formato específico para outro.
	 *
	 * @param string	$data			A data a ser formatada.
	 * @param string 	$formato_de 	O formato atual da data. Padrão é 'Y-m-d'.
	 * @param string 	$formato_para 	O formato desejado para a conversão. Padrão é 'd/m/Y'.
	 * @return null 					A data formatada ou null se a conversão falhar.
	 */
	public function formata_data($data, $formato_de = 'Y-m-d', $formato_para = 'd/m/Y') {
		// Cria um objeto DateTime a partir do formato de entrada
		$d = DateTime::createFromFormat($formato_de, $data);

		// Se a criação do objeto DateTime for bem-sucedida, retorna a data formatada
		if ($d) {
			return $d->format($formato_para);
		}

		return null;
	}

	/**
	 * Valida se uma data está no formato correto.
	 *
	 * @param string 	$data 	A data a ser validada.
	 * @return bool 			Retorna verdadeiro se a data estiver no formato correto, falso caso contrário.
	 */
	function valida_data($data, $formato = 'd/m/Y') {
		$d = DateTime::createFromFormat($formato, $data);
		return $d && $d->format($formato) === $data;
	}

	/**
	 * Valida se o valor é numérico e não negativo, considerando formatos monetários brasileiros.
	 *
	 * @param string $valor O valor monetário a ser validado.
	 * @return bool Retorna verdadeiro se o valor é um número válido e não negativo, falso caso contrário.
	 *
	 * Observação: Esta função assume que o valor pode estar no formato 'R$ 1.000,00' e ajusta para um formato numérico.
	 */
	function valida_valor($valor) {
		$valor = preg_replace('/[^\d,]/', '', $valor); // Remove tudo exceto dígitos e vírgula
		$valor = str_replace(',', '.', $valor); // Troca vírgula por ponto para conversão
		return is_numeric($valor) && $valor >= 0;
	}

	/**
	 * Valida se um CPF é válido através da verificação dos dígitos verificadores.
	 *
	 * @param string $cpf O CPF a ser validado.
	 * @return bool Retorna verdadeiro se o CPF é válido, falso caso contrário.
	 *
	 * Observação: O CPF deve ser fornecido com ou sem formatação (pontos e traço).
	 */
	function valida_cpf($cpf) {
		$cpf = preg_replace('/[^0-9]/', '', $cpf);
		if (strlen($cpf) != 11 || preg_match('/(\d)\1{10}/', $cpf)) {
			return false;
		}

		for ($t = 9; $t < 11; $t++) {
			for ($d = 0, $c = 0; $c < $t; $c++) {
				$d += $cpf[$c] * (($t + 1) - $c);
			}
			$d = ((10 * $d) % 11) % 10;
			if ($cpf[$c] != $d) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Valida se um CNPJ é válido através da verificação dos dígitos verificadores.
	 *
	 * @param string $cnpj O CNPJ a ser validado.
	 * @return bool Retorna verdadeiro se o CNPJ é válido, falso caso contrário.
	 *
	 * Observação: O CNPJ deve ser fornecido com ou sem formatação (pontos, barra e traço).
	 */
	function valida_cnpj($cnpj) {
		$cnpj = preg_replace('/[^0-9]/', '', $cnpj);
		if (strlen($cnpj) != 14) {
			return false;
		}

		$calcularCnpj = function ($cnpj, $posicoes = 12) {
			$soma = 0;
			$posicao = $posicoes - 7;
			for ($i = $posicoes; $i >= 1; $i--) {
				$soma += $cnpj[$posicoes - $i] * $posicao--;
				if ($posicao < 2) {
					$posicao = 9;
				}
			}
			return $soma % 11 < 2 ? 0 : 11 - $soma % 11;
		};

		if ($cnpj[12] != $calcularCnpj($cnpj, 12) || $cnpj[13] != $calcularCnpj($cnpj, 13)) {
			return false;
		}
		return true;
	}

	/**
	 * Valida se uma data é maior que a data atual.
	 * 
	 * @param string 	$data 		A data a ser validada.
	 * @param string 	$formato 	O formato da data.
	 * @return bool 				Retorna true se a data for maior que a data atual, false caso contrário.
	 */
	protected function data_maior_que_hoje($data, $formato) {
		// Assegura que a hora esteja zerada e cria o objeto DateTime para a data do usuário
		$usuario_data = DateTime::createFromFormat($formato . ' H:i:s', $data . ' 00:00:00');

		// Verificar se a criação da data foi bem-sucedida
		if (!$usuario_data) {
			return false;  // Se a data não puder ser criada corretamente, tratar como não maior que hoje
		}

		// Obter a data atual do WordPress, zerar o tempo e criar o objeto DateTime
		$current_timestamp = current_time('timestamp');
		$current_data = new DateTime();
		$current_data->setTimestamp($current_timestamp);
		$current_data->setTime(0, 0, 0);  // Zerar hora, minuto e segundo para comparar apenas a data

		// Comparar as datas
		return $usuario_data > $current_data;
	}

	/**
	 * Compara duas datas e verifica qual é a maior.
	 * 
	 * @param string 	$data1 		A primeira data a ser comparada.
	 * @param string 	$data2 		A segunda data a ser comparada.
	 * @param string 	$formato 	O formato das datas.
	 * @return int 					Retorna 1 se a primeira data for maior, -1 se a segunda for maior, e 0 se forem iguais.
	 */
	function compara_datas($data1, $data2, $formato = 'd/m/Y') {
		// Cria objetos DateTime para ambas as datas
		$dateTime1 = DateTime::createFromFormat($formato . ' H:i:s', $data1 . ' 00:00:00');
		$dateTime2 = DateTime::createFromFormat($formato . ' H:i:s', $data2 . ' 00:00:00');

		// Verifica se a criação dos objetos DateTime foi bem-sucedida
		if (!$dateTime1 || !$dateTime2) {
			throw new Exception("Formato de data inválido ou data fornecida incorreta.");
		}

		// Compara as duas datas
		if ($dateTime1 > $dateTime2) {
			return 1;
		} elseif ($dateTime1 < $dateTime2) {
			return -1;
		} else {
			return 0;
		}
	}
}
