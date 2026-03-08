// js/home.js
import { apiRequest } from "./api.js";
import { logJsInfo, logJsError } from "./logger.js";

function $(id) {
  return document.getElementById(id);
}

function obterUsuarioLocal() {
  try {
    const raw = localStorage.getItem("usuario");
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

function montarSaudacao() {
  const el = $("homeSaudacao");
  if (!el) return;

  const usuario = obterUsuarioLocal();
  const nomeCompleto = String(usuario?.nome ?? "").trim();
  const primeiroNome = nomeCompleto.split(" ").filter(Boolean)[0] || "seja bem-vindo";

  el.textContent = `Bem-vindo, ${primeiroNome}.`;
}

function setEstado({ loading = false, erro = false } = {}) {
  const loadingEl = $("homeLoading");
  const conteudoEl = $("homeConteudo");
  const erroEl = $("homeErro");

  if (loadingEl) loadingEl.classList.toggle("d-none", !loading);
  if (conteudoEl) conteudoEl.classList.toggle("d-none", loading || erro);
  if (erroEl) erroEl.classList.toggle("d-none", !erro);
}

function preencherFrase(frase, autor) {
  const fraseEl = $("homeFrase");
  const autorEl = $("homeAutor");

  if (fraseEl) {
    fraseEl.textContent = frase ? `“${frase}”` : "“Sem frase disponível no momento.”";
  }

  if (autorEl) {
    autorEl.textContent = autor ? `— ${autor}` : "";
  }
}

function preencherImagem(caminho) {
  const img = $("homeImagem");
  const placeholder = $("homeImagemPlaceholder");

  if (!img) return;

  if (!caminho) {
    img.classList.add("d-none");
    if (placeholder) placeholder.classList.remove("d-none");
    return;
  }

  img.onload = () => {
    img.classList.remove("d-none");
    if (placeholder) placeholder.classList.add("d-none");
  };

  img.onerror = () => {
    img.classList.add("d-none");
    if (placeholder) placeholder.classList.remove("d-none");
  };

  img.src = caminho;
}

async function carregarHome() {
  setEstado({ loading: true, erro: false });

  try {
    const resp = await apiRequest("obter_home", null, "GET");

    if (!resp?.sucesso) {
      setEstado({ loading: false, erro: true });
      return;
    }

    const dados = resp?.dados || {};
    const frase = String(dados?.frase?.texto ?? "").trim();
    const autor = String(dados?.frase?.autor ?? "").trim();
    const imagem = String(dados?.imagem?.caminho ?? "").trim();

    preencherFrase(frase, autor);
    preencherImagem(imagem);

    setEstado({ loading: false, erro: false });

    logJsInfo({
      origem: "home.js",
      mensagem: "Home carregada com sucesso",
      autor: autor || null,
      imagem: imagem || null
    });
  } catch (err) {
    setEstado({ loading: false, erro: true });

    logJsError({
      origem: "home.js",
      mensagem: "Erro ao carregar conteúdo da Home",
      detalhe: err?.message || String(err),
      stack: err?.stack || null
    });
  }
}

document.addEventListener("DOMContentLoaded", async () => {
  montarSaudacao();
  await carregarHome();
});