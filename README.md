# TestLink 3.0

Sistema web de gerenciamento de **testes manuais** em PHP puro + MySQL + HTML/CSS/JS vanilla (sem frameworks).
Cobre o ciclo completo: projetos → suítes → casos de teste → atribuição → execução com evidências → defeitos → reteste → relatórios imprimíveis.

## Requisitos

- PHP 8.0 ou superior (extensões `pdo_mysql`, `fileinfo`, `mbstring` — habilitadas por padrão no XAMPP/WAMP)
- MySQL 5.7+ ou MariaDB 10.3+
- Apache (XAMPP, WAMP, LAMP). Opcional: `php -S` para testes locais.

## Instalação

1. **Copie a pasta `testlink`** para o diretório público do servidor:
   - XAMPP: `C:\xampp\htdocs\testlink`
   - WAMP: `C:\wamp64\www\testlink`
   - LAMP: `/var/www/html/testlink`
2. **Crie o banco**: abra o phpMyAdmin → *Importar* → selecione `database.sql`.
   Ou pelo terminal: `mysql -u root -p < database.sql` (o script cria o banco `testlink3`).
   O nome `testlink3` evita conflito com uma instalação do TestLink 1.9 (banco `testlink`), e o script **não apaga tabelas existentes**.
   Para reinstalar do zero, remova antes o banco `testlink3`.
3. **Configure a conexão** em `config/config.php` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
   No XAMPP padrão o usuário é `root` com senha vazia, que já é o valor configurado.
4. **Permissão de escrita**: a pasta `uploads/evidences/` precisa ser gravável pelo servidor web
   (no Linux: `chown -R www-data:www-data uploads && chmod -R 755 uploads`).
5. **Acesse** `http://localhost/testlink/`.

### Usuários iniciais (senha de todos: `Admin@123`)

| Perfil        | E-mail                   |
|---------------|--------------------------|
| Administrador | admin@testlink.local     |
| Tester        | tester@testlink.local    |
| Desenvolvedor | dev@testlink.local       |
| Usuário (leitura) | viewer@testlink.local |

> **Troque as senhas** após o primeiro acesso (menu *Meu perfil*) e remova os usuários de demonstração que não usar.

Teste rápido sem Apache: dentro da pasta `testlink`, execute `php -S localhost:8000` e acesse `http://localhost:8000`.

## Estrutura de pastas

```
testlink/
├── index.php, login.php, logout.php, dashboard.php, profile.php
├── users.php, projects.php, suites.php, cases.php, case_form.php, case_view.php, assignments.php, settings.php   (admin)
├── my_tests.php, execute.php, execution_view.php, history.php                                                   (tester)
├── defects.php, defect_view.php                                                                                  (defeitos)
├── reports.php, report_execution.php, report_coverage.php, report_defects.php, report_case.php                  (relatórios)
├── file.php                    entrega evidências com checagem de permissão
├── config/config.php          credenciais do banco e constantes
├── includes/                  bootstrap, db (PDO), helpers, auth, upload, defects_lib, header/footer
├── assets/css, js, img        estilos, scripts e favicon
├── uploads/evidences/         imagens enviadas (acesso direto bloqueado por .htaccess)
└── database.sql               estrutura completa + dados iniciais
```

## Perfis e permissões

| Perfil | Pode |
|---|---|
| **Administrador** | CRUD de usuários, projetos, suítes e casos; atribuir casos a testers; **encaminhar chamados ao desenvolvedor** (só urgência + desenvolvedor); relatórios; configurações |
| **Tester** | Ver casos atribuídos (Kanban), executar (Aprovado/Reprovado/Bloqueado), assentamento, múltiplas evidências, reteste, histórico próprio. **Não encaminha ao desenvolvedor** |
| **Desenvolvedor** | Kanban "Meus Chamados" com os chamados encaminhados a ele; status Aberto / Em andamento / Concluído; comentário de retorno **e fotos**. Ao concluir, perde o acesso e o chamado volta ao Admin |
| **Usuário (leitura)** | Ver dashboard, casos, execuções, defeitos e relatórios — sem editar nada |

## Fluxo principal

1. Admin cria projeto → suítes → casos de teste (cada edição de conteúdo gera uma nova versão no histórico).
2. Admin atribui casos ao tester em **Atribuições**.
3. Tester abre **Meus Testes → Executar**, escolhe o resultado, escreve o assentamento e anexa prints.
4. Se **Reprovado**, o tester explica no assentamento e anexa fotos se quiser. O sistema abre o chamado como **Aguardando triagem** (o tester não escolhe desenvolvedor).
5. **Tomada de Decisão (Admin)**: o Admin abre o chamado, ajusta a **urgência** e escolhe **um** destino (sem campo de comentário):
   - **Desenvolvedor** → status *Aberto*; ou
   - **Tester — Reteste** → o caso volta ao Kanban do tester como **Reteste** (já possível na triagem, sem passar pelo dev).
6. O **Desenvolvedor** vê o chamado no seu Kanban, trabalha (Aberto ⇄ Em andamento), comenta e anexa fotos, e **conclui**. Ao concluir ele perde o acesso e o chamado volta ao Admin (*Concluído pelo Dev*), que toma uma **nova decisão**: de novo ao dev ou ao tester para reteste.
7. **Reteste** (tester escolhido pelo Admin): aprovado → chamado **Fechado**; reprovado → **Reaberto** (volta ao Admin para nova decisão).
8. Admin/usuário de leitura gera e imprime os relatórios.

> O desenvolvedor não tem a opção *Reaberto*: ela só ocorre automaticamente quando o reteste reprova. O Admin também pode fechar um chamado manualmente. Chamados *Aguardando triagem*, *Concluído pelo Dev* e *Reaberto* aparecem no dashboard do Admin como "aguardando decisão".

## Cadastro com aprovação do administrador

Na tela de login há o link **Solicitar cadastro**. A pessoa informa nome, e-mail, **função** (Tester, Desenvolvedor ou Usuário de leitura) e senha.
O cadastro fica **pendente** e ela **não consegue entrar** até um administrador aprovar em **Usuários → Solicitações de cadastro pendentes**
(o admin pode ajustar a função antes de aprovar, ou recusar). O dashboard do admin avisa quando há solicitações.
A função *Administrador* não pode ser solicitada no formulário; só o admin cria outro admin.

> Já instalou a versão anterior? Execute uma vez `migration_registro.sql` no banco `testlink3`.

## Kanban "Meus Testes" (perfil Tester)

A tela Meus Testes é um quadro Kanban escuro (preto/grafite com vermelho) com as colunas **Aguardando**, **Em Andamento** e **Concluído**.

- **Arrastar e soltar** os cartões entre colunas; o status é gravado na hora. Cada cartão também tem o seletor *Mover para* (teclado/toque/sem JavaScript).
- **Aguardando → Em Andamento**: livre. Registrar uma execução também move o cartão para Em Andamento.
- **→ Concluído**: bloqueado enquanto não houver **assentamento** *e* **ao menos uma foto de evidência** na execução do ciclo atual.
  A mensagem diz o que falta. A regra é aplicada no servidor (`includes/kanban.php`), valendo para arrastar, seletor ou POST direto.
- Quando um defeito do caso é marcado como **Corrigido**, o cartão volta para Aguardando (reteste) e começa um novo ciclo.
- **Criticidade** (prioridade do caso) em etiquetas vermelhas de intensidade crescente: Baixo < Médio < Alto < Crítico (o mais vibrante, com brilho).

> Já instalou antes? Execute uma vez, nesta ordem, `migration_registro.sql`, `migration_kanban.sql`, `migration_fluxo.sql` e `migration_decisao.sql` no banco `testlink3` (nenhuma apaga dados).
> Para rodar testes sem tocar no seu banco, aponte para outro com a variável de ambiente `TESTLINK_DB`.

## Relatórios

Relatórios: execução por projeto/período, cobertura por suíte, defeitos abertos e relatório individual do caso (com evidências).

- **Acentuação/UTF-8**: o PDF embute uma fonte Unicode (só os glifos usados). Procura `assets/fonts/pdf-regular.ttf` e `pdf-bold.ttf`
  (opcional: coloque aqui uma fonte TrueType de sua escolha) e, na falta, usa Arial (Windows) / Liberation / DejaVu (Linux).
- **Baixar PDF**: gera um PDF próprio (A4) com cabeçalho com **logo e nome da empresa**, cartões de resumo, tabelas coloridas com cabeçalho repetido a cada página,
  imagens das evidências embutidas e rodapé "Página X de Y". Não usa captura de tela nem a impressão do navegador.
- **Logo da empresa**: envie em *Configurações* (JPG/PNG). Sem logo, usa o ícone padrão. O nome no cabeçalho é o "Nome do sistema / empresa".
- **Imprimir** (CSS `@media print`) e **Visão limpa** continuam disponíveis nas telas.
- Evidências JPG e PNG entram no PDF. Arquivos **WEBP** só entram se a extensão `gd` estiver ativa no PHP
  (no XAMPP: remova o `;` de `extension=gd` no `php.ini` e reinicie o Apache); caso contrário aparece um aviso no lugar da imagem.

## Segurança implementada

- **PDO com prepared statements** em todas as consultas (sem concatenar entrada do usuário).
- **Senhas** com `password_hash` / `password_verify`; limite de tentativas de login por sessão; `session_regenerate_id` ao entrar.
- **XSS**: toda saída passa por `e()` (`htmlspecialchars`).
- **CSRF**: token por sessão validado em todos os formulários POST.
- **Upload**: somente JPG/PNG/WEBP, tipo real validado (`finfo` + `getimagesize`), nome aleatório, limite de tamanho e quantidade; a pasta é bloqueada via `.htaccess` e as imagens só saem por `file.php`, que exige login e permissão.
- Controle de acesso por perfil em cada página (`require_role`), incluindo escopo dos dados (tester só vê o que é dele; desenvolvedor só os defeitos atribuídos).
- Cookies de sessão `HttpOnly` + `SameSite=Lax`.

Para produção, use HTTPS, desative `display_errors` no `php.ini` e mantenha `uploads/` fora do alcance público se possível.

## Observações

- O sistema funciona offline: não há dependências externas (ícones em SVG inline).
- O limite de upload efetivo é o menor entre *Configurações → tamanho máximo* e `upload_max_filesize`/`post_max_size` do `php.ini`.
