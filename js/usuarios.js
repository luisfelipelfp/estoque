// js/usuarios.js
import { logJsInfo, logJsError } from "./logger.js";

function $(id) {
  return document.getElementById(id);
}

function obterUsuarioLocal() {
  try {
    const raw = localStorage.getItem("usuario");
    return raw ? JSON.parse(raw) : null;
  } catch (err) {
    logJsError({
      origem: "usuarios.js",
      mensagem: "Erro ao ler usuário do localStorage",
      detalhe: err?.message || String(err),
      stack: err?.stack || null
    });
    return null;
  }
}

function usuarioEhAdmin(usuario) {
  const nivel = String(usuario?.nivel ?? "").trim().toLowerCase();
  return nivel === "admin" || nivel === "administrador";
}

function aplicarProtecaoVisual() {
  const usuario = obterUsuarioLocal();
  const tbody = $("tabelaUsuarios");
  const topo = $("usuariosStatusTopo");

  if (!usuarioEhAdmin(usuario)) {
    if (topo) {
      topo.textContent = "Acesso restrito.";
    }

    if (tbody) {
      tbody.innerHTML = `
        <tr>
          <td colspan="6" class="text-center text-danger">
            Esta área é restrita para administradores.
          </td>
        </tr>
      `;
    }

    return;
  }

  if (topo) {
    topo.textContent = "Área administrativa pronta para integração.";
  }

  logJsInfo({
    origem: "usuarios.js",
    mensagem: "Tela de usuários carregada para administrador",
    usuario: usuario?.nome || null
  });
}

document.addEventListener("DOMContentLoaded", () => {
  aplicarProtecaoVisual();
});