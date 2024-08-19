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

        // Definir as configurações da mídia
        const file_frame = new wp.media({
          title: "Selecione ou envie um novo relatório",
          button: {
            text: "Selecionar este relatório",
          },
          multiple: false,
        });

        // Capturar a seleção do arquivo
        file_frame.on("select", function () {
          const attachment = file_frame
            .state()
            .get("selection")
            .first()
            .toJSON();
          const fieldsetMedia = campo.closest("fieldset");
          fieldsetMedia.querySelector('input[type="text"]').value =
            attachment.url;
          fieldsetMedia.querySelector('input[type="hidden"]').value =
            attachment.filesizeInBytes;
        });

        // Abrir o frame de seleção de arquivo
        file_frame.open();
      });
    });
});
