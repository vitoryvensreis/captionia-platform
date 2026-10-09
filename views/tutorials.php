<?php use CaptionIA\Core\View; ?>
<main class="dashboard-wrap tutorial-page">
  <section class="dashboard-head"><div>
    <div class="eyebrow">CENTRAL DE AJUDA</div>
    <h1>Tutoriais do Caption IA</h1>
    <p>Instalação, ativação, configuração da Groq e solução dos problemas mais comuns.</p>
  </div></section>
  <nav class="tutorial-jump" aria-label="Guias"><a href="#windows"><i class="bi bi-windows"></i> Windows</a><a href="#mac"><i class="bi bi-apple"></i> macOS</a><a href="#groq"><i class="bi bi-key"></i> Integrar Groq</a><a href="#primeiros-passos"><i class="bi bi-play-circle"></i> Primeiros passos</a><a href="#duvidas"><i class="bi bi-question-circle"></i> Solução de problemas</a></nav>
  <section class="panel" id="windows">
    <div class="eyebrow">01 · WINDOWS</div><h2>Como instalar no Windows</h2>
    <ol class="tutorial-steps">
      <li>Acesse <a href="/minha-conta">Minha conta</a>, faça login e baixe a versão autorizada para Windows.</li>
      <li>Extraia o arquivo ZIP inteiro. <strong>Não execute o instalador de dentro do ZIP.</strong></li>
      <li>Salve seus projetos e feche o Premiere Pro e o After Effects.</li>
      <li>Execute <code>INSTALAR_CAPTION_IA.bat</code> na pasta extraída e siga o instalador. Pode ser necessário autorizar permissões do Windows.</li>
      <li>Abra o Premiere e selecione <strong>Janela → Extensões (legado) → Caption IA</strong>. Faça login.</li>
      <li>Em caso de dúvida, veja o status da chave em <a href="/minha-conta#integracao-groq">Minha conta → Groq</a>.</li>
    </ol>
    <p class="muted">Se a extensão não aparecer, confira a compatibilidade da versão do Premiere e se as extensões CEP legadas estão habilitadas.</p>
  </section>
  <section class="panel" id="mac">
    <div class="eyebrow">02 · macOS</div><h2>Como instalar no Mac</h2>
    <p>Para a distribuição ZIP não assinada, o método mais simples e previsível é copiar a extensão manualmente pelo Finder. Não exige executar scripts bloqueados pelo Gatekeeper.</p>
    <ol class="tutorial-steps">
      <li>Baixe o pacote na sua conta (o mesmo ZIP fornece a pasta para instalação manual no Mac). Extraia o ZIP e localize a pasta <code>CaptionIA</code>. Se o pacote contiver <code>CaptionIA_Dados.zip</code>, extraia-o também.</li>
      <li>Feche o Premiere Pro.</li>
      <li>No Finder, pressione <kbd>Command</kbd> + <kbd>Shift</kbd> + <kbd>G</kbd> e cole o caminho:
        <div class="tutorial-code"><code>~/Library/Application Support/Adobe/CEP/extensions/</code></div>
      </li>
      <li>Crie <code>CEP/extensions</code> se não existir. Copie a pasta <code>CaptionIA</code> para dentro de <code>extensions</code>. Deve ficar <code>extensions/CaptionIA/CSXS/manifest.xml</code>, sem outra pasta CaptionIA duplicada.</li>
      <li>Reabra o Premiere e acesse <strong>Janela → Extensões (legado) → Caption IA</strong>.</li>
    </ol>
    <details><summary>O Premiere não mostra o Caption IA?</summary><p>Para testar uma extensão CEP que ainda não foi assinada pela Adobe, pode ser necessário ativar o modo de desenvolvimento CEP. No Terminal, execute somente se confiar na origem do plugin:</p><div class="tutorial-code"><code>for v in 9 10 11 12 13 14 15; do defaults write "com.adobe.CSXS.$v" PlayerDebugMode -string "1"; done</code></div><p>Depois reinicie o Premiere. Uma extensão assinada é o formato recomendado para distribuição pública.</p></details>
    <details><summary>O Mac diz que o instalador .command está danificado?</summary><p>Esse aviso pode ser uma proteção do macOS contra scripts não assinados, e não necessariamente um ZIP corrompido. Prefira o método manual acima. Não desative globalmente o Gatekeeper.</p></details>
    <p class="muted">Não precisa cadastrar a chave no Keychain do Mac: nas versões com Groq vinculada à conta, toda a configuração é feita pelo site.</p>
  </section>
  <section class="panel" id="groq">
    <div class="eyebrow">03 · INTEGRAÇÃO DE IA</div><h2>Conectar sua chave Groq uma única vez</h2>
    <ol class="tutorial-steps">
      <li>Abra <a href="https://console.groq.com/keys" target="_blank" rel="noopener noreferrer">console.groq.com/keys</a>, entre na sua conta Groq e crie uma chave de API.</li>
      <li>Acesse <a href="/minha-conta#integracao-groq">Minha conta → Minha chave Groq</a> no Caption IA.</li>
      <li>Cole a chave <code>gsk_...</code> no formulário e clique <strong>Validar e salvar</strong>. A plataforma verifica o acesso a Whisper Large V3 e GPT-OSS 120B antes de armazenar.</li>
      <li>Abra o Caption IA no Premiere, faça login na mesma conta e clique <strong>Verificar integração</strong> se necessário.</li>
      <li>Pronto! O processamento de IA usa sua própria conta Groq e seus limites, mesmo se alternar entre Mac e Windows.</li>
    </ol>
    <p class="muted"><i class="bi bi-shield-lock"></i> Segurança: a chave é protegida com criptografia no servidor e não é exposta ao plugin, aos relatórios ou em sua página da conta. Para remover ou trocar, use o mesmo formulário.</p>
  </section>
  <section class="panel" id="primeiros-passos">
    <div class="eyebrow">04 · PRIMEIROS PASSOS</div><h2>Da transcrição à Montagem Inteligente</h2>
    <ol class="tutorial-steps">
      <li>Abra um projeto e uma sequência no Premiere Pro.</li>
      <li>No Caption IA, acesse <strong>Transcrever</strong>, escolha áudio ou timeline e aguarde a transcrição palavra a palavra.</li>
      <li>Abra <strong>Legendas</strong> para selecionar o visual e aplicar os templates.</li>
      <li>Para editar takes automaticamente, abra <strong>Auto Edit</strong>, escolha <strong>Montagem livre</strong> ou <strong>Por roteiro</strong> e revise a seleção antes de aplicar os cortes.</li>
      <li>Use os recursos adicionais como AutoSFX, trilha e efeitos conforme a necessidade.</li>
    </ol>
    <p class="muted">Teste novas automações em uma cópia do projeto para preservar seu material original.</p>
  </section>
  <section class="panel" id="duvidas">
    <div class="eyebrow">05 · DÚVIDAS</div><h2>Problemas comuns</h2>
    <details><summary>Minha chave Groq não conecta</summary><p>Confira se começa com <code>gsk_</code> e se sua organização Groq oferece acesso aos modelos exigidos. Substitua ou valide a chave pela página da conta, nunca enviando a chave para suporte.</p></details>
    <details><summary>A Groq mostrou limite de tokens</summary><p>É um limite temporário da sua organização Groq. O Caption IA mostra a última leitura de tokens e aguarda nas retentativas de montagem quando possível. Várias chaves da mesma organização não multiplicam o limite.</p></details>
    <details><summary>Posso utilizar no Mac e Windows?</summary><p>Sim, nas versões compatíveis. A chave Groq é associada à conta web, enquanto o limite de dispositivos obedece ao plano comprado.</p></details>
    <details><summary>O plugin pede chave no Mac, mesmo depois de eu cadastrar no site</summary><p>Atualize o plugin para a versão que suporta integração Groq por conta. Instalações antigas exigiam salvar uma credencial localmente.</p></details>
    <details><summary>Onde baixo o instalador?</summary><p>Na área <a href="/minha-conta">Minha conta</a>, quando sua licença permitir o download e houver um pacote publicado para seu sistema.</p></details>
  </section>
</main>
