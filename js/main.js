// vamos impedir o salvamento automático do post para impedir a criação do post
// uma vez que dependemos da validação dos campos para permitir o cadastro.
if (typeof autosave === "function") {
  wp.autosave.server.suspend();
}

// Associar o evento de clique ao botão "Selecionar Mídia"
document.addEventListener("DOMContentLoaded", function () {
  // para todos os inputs que possuem o atributo "maxlength" definido
  // vamos adicionar um contador de caracteres e personalizar a mensagem usando 
  // a função "setCustomValidity" do HTML5
  document.querySelectorAll('input[maxlength], textarea[maxlength]').forEach(function (element) {
    const maxlength = element.getAttribute('maxlength');

    // Função para formatar a mensagem
    function atualizarMensagemCaracteres(element) {
      const caracteresRestantes = maxlength - element.value.length;
      if (caracteresRestantes === 0) {
        return `Você atingiu o limite de ${maxlength} caracteres.`;
      }
      return caracteresRestantes === 1
        ? `Resta 1 caractere de ${maxlength}.`
        : `Restam ${caracteresRestantes} caracteres de ${maxlength}.`;
    }

    // criamos um elemento span para exibir o contador de caracteres
    const span = document.createElement('span');
    span.className = 'contador-caracteres';
    span.textContent = atualizarMensagemCaracteres(element); // Define o texto inicial

    element.insertAdjacentElement('afterend', span);

    // torna o elemento span visível ao clicar no element
    element.addEventListener('focus', function () {
      span.style.display = 'block';
    });

    // esconde o elemento span ao clicar fora do element
    element.addEventListener('blur', function () {
      span.style.display = 'none';
    });

    // Atualiza o contador de caracteres ao digitar no element
    element.addEventListener('input', function () {
      span.textContent = atualizarMensagemCaracteres(element);
    });
  });

  document
    .querySelectorAll(".cbbox-selecionar-midia")
    .forEach(function (button) {
      button.addEventListener("click", function () {
        const campo = this;

        // Configuração inicial do frame de mídia
        const mediaSettings = {
          title: "Selecione ou envie um novo arquivo",
          button: {
            text: "Utilizar este arquivo",
          },
          multiple: false
        };

        // Verificar se o atributo para formatos válidos existe e adicionar ao filtro de tipos
        const formatos_validos = campo.getAttribute('data-formatos-validos');
        if (formatos_validos) {
          mediaSettings.library = {
            type: formatos_validos.split(',')
          };
        }

        // Criar o frame de seleção de mídia com as configurações definidas
        const file_frame = new wp.media(mediaSettings);

        // Capturar a seleção do arquivo
        file_frame.on("select", function () {
          const attachment = file_frame
            .state()
            .get("selection")
            .first()
            .toJSON();

          const fieldsetMedia = campo.closest("fieldset");
          const inputUrl = fieldsetMedia.querySelector('input[id$="_url"]');
          const inputId = fieldsetMedia.querySelector(
            'input[id$="_id"]'
          );
          inputUrl.setAttribute('value', attachment.url);
          inputId.setAttribute('value', attachment.id);
        });

        // Abrir o frame de seleção de arquivo
        file_frame.open();
      });
    });

  // Associar o evento de clique ao botão "Remover Mídia"
  document
    .querySelectorAll(".cbbox-remover-midia")
    .forEach(function (button) {
      button.addEventListener("click", function () {
        const campo = this;
        const fieldsetMedia = campo.closest("fieldset");
        const inputUrl = fieldsetMedia.querySelector('input[id$="_url"]');
        const inputId = fieldsetMedia.querySelector('input[id$="_id"]');

        if (inputUrl) {
          inputUrl.setAttribute('value', '');
        }

        if (inputId) {
          inputId.setAttribute('value', '');
        }
      });
    });
});
