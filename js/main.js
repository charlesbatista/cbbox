// vamos impedir o salvamento automático do post para impedir a criação do post
// uma vez que dependemos da validação dos campos para permitir o cadastro.
if (typeof autosave === "function") {
  wp.autosave.server.suspend();
}

// Associar o evento de clique ao botão "Selecionar Mídia"
document.addEventListener("DOMContentLoaded", function () {
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

        // Verificar se o atributo data-formatos-aceitos existe e adicionar ao filtro de tipos
        const formatosAceitos = campo.getAttribute('data-formatos-aceitos');
        if (formatosAceitos) {
          mediaSettings.library = {
            type: formatosAceitos.split(',') // Adicionar os formatos aceitos, separando por vírgula
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
});
