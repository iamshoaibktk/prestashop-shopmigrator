{**
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *}
<link rel="stylesheet" href="{$sm_css|escape:'html':'UTF-8'}">
<div id="sm-app"
     data-ajax="{$sm_ajax_url|escape:'html':'UTF-8'}"
     data-state="{$sm_state_json|escape:'html':'UTF-8'}"
     data-types="{$sm_types_json|escape:'html':'UTF-8'}"
     data-needs="{$sm_needs_json|escape:'html':'UTF-8'}"
     data-t-testing="{l s='Connecting…' d='Modules.Shopmigrator.Admin'}"
     data-t-running="{l s='Migrating…' d='Modules.Shopmigrator.Admin'}"
     data-t-paused="{l s='Paused' d='Modules.Shopmigrator.Admin'}"
     data-t-done="{l s='Migration complete' d='Modules.Shopmigrator.Admin'}"
     data-t-error="{l s='The migration stopped' d='Modules.Shopmigrator.Admin'}"
     data-t-waiting="{l s='Waiting' d='Modules.Shopmigrator.Admin'}"
     data-t-working="{l s='Working on' d='Modules.Shopmigrator.Admin'}"
     data-t-confirm="{l s='The selected data on this shop will be deleted and replaced by the old shop’s data. Continue?' d='Modules.Shopmigrator.Admin' js=1}"
     data-t-g-products="{l s='products' d='Modules.Shopmigrator.Admin'}"
     data-t-g-combinations="{l s='combinations' d='Modules.Shopmigrator.Admin'}"
     data-t-g-categories="{l s='categories' d='Modules.Shopmigrator.Admin'}"
     data-t-g-images="{l s='images' d='Modules.Shopmigrator.Admin'}"
     data-t-g-customers="{l s='customers' d='Modules.Shopmigrator.Admin'}"
     data-t-g-orders="{l s='orders' d='Modules.Shopmigrator.Admin'}"
     data-t-reset="{l s='Start a new migration? The current progress and report will be cleared.' d='Modules.Shopmigrator.Admin' js=1}"
     data-t-nolang="{l s='Not installed here, will be added:' d='Modules.Shopmigrator.Admin'}"
     data-t-inprogress="{l s='In progress' d='Modules.Shopmigrator.Admin'}"
     data-t-finished="{l s='Done' d='Modules.Shopmigrator.Admin'}"
     data-t-persec="{l s='per second' d='Modules.Shopmigrator.Admin'}">

  <ol class="sm-stepper" aria-label="{l s='Steps' d='Modules.Shopmigrator.Admin'}">
    <li data-step="1"><span class="sm-dot">1</span><span>{l s='Connect' d='Modules.Shopmigrator.Admin'}</span></li>
    <li data-step="2"><span class="sm-dot">2</span><span>{l s='Choose data' d='Modules.Shopmigrator.Admin'}</span></li>
    <li data-step="3"><span class="sm-dot">3</span><span>{l s='Migrate' d='Modules.Shopmigrator.Admin'}</span></li>
    <li data-step="4"><span class="sm-dot">4</span><span>{l s='Report' d='Modules.Shopmigrator.Admin'}</span></li>
  </ol>

  {* Step 1: connect *}
  <section class="sm-card sm-pane" data-pane="1">
    <header class="sm-card-head">
      <div class="sm-icon"><i class="material-icons">link</i></div>
      <div>
        <h2>{l s='Connect your old shop' d='Modules.Shopmigrator.Admin'}</h2>
        <p>{l s='Install the Shop Migrator Bridge module on your old shop (PrestaShop 1.6, 1.7, 8 or 9).' d='Modules.Shopmigrator.Admin'} {l s='Open its configuration page and copy the shop address and the key here.' d='Modules.Shopmigrator.Admin'}</p>
      </div>
    </header>
    <div class="sm-card-body">
      <div class="sm-fields">
        <label class="sm-field">
          <span>{l s='Old shop address' d='Modules.Shopmigrator.Admin'}</span>
          <input type="url" id="sm-url" value="{$sm_url|escape:'html':'UTF-8'}" placeholder="https://old-shop.com/" autocomplete="off">
        </label>
        <label class="sm-field">
          <span>{l s='Connection key' d='Modules.Shopmigrator.Admin'}</span>
          <input type="text" id="sm-key" class="sm-mono" value="{$sm_key|escape:'html':'UTF-8'}" autocomplete="off" spellcheck="false">
        </label>
      </div>
      <div id="sm-test-msg" aria-live="polite"></div>
      <div id="sm-info" class="sm-source" hidden></div>
    </div>
    <footer class="sm-card-foot">
      <button type="button" class="sm-btn sm-btn-secondary" id="sm-test"><i class="material-icons">power</i> {l s='Test connection' d='Modules.Shopmigrator.Admin'}</button>
      <button type="button" class="sm-btn sm-btn-primary" id="sm-next" disabled>{l s='Next' d='Modules.Shopmigrator.Admin'} <i class="material-icons">arrow_forward</i></button>
    </footer>
  </section>

  {* Step 2: choose *}
  <section class="sm-card sm-pane" data-pane="2" hidden>
    <header class="sm-card-head">
      <div class="sm-icon"><i class="material-icons">checklist</i></div>
      <div>
        <h2>{l s='Choose what to migrate' d='Modules.Shopmigrator.Admin'}</h2>
        <p id="sm-source-line"></p>
      </div>
    </header>
    <div class="sm-card-body">
      <div id="sm-typelist" class="sm-tiles"></div>

      <div class="sm-settings">
        <div class="sm-setting">
          <div class="sm-setting-label">{l s='Mode' d='Modules.Shopmigrator.Admin'}</div>
          <div class="sm-choice" role="radiogroup">
            <label><input type="radio" name="sm-mode" value="fresh" checked><span><strong>{l s='Fresh copy, IDs kept.' d='Modules.Shopmigrator.Admin'}</strong>{l s='Fresh copy: replace the selected data on this shop' d='Modules.Shopmigrator.Admin'}</span></label>
            <label><input type="radio" name="sm-mode" value="new"><span><strong>{l s='New data only' d='Modules.Shopmigrator.Admin'}</strong>{l s='New data only: add what was created on the old shop since the last migration (new orders, customers, products…), keep everything here' d='Modules.Shopmigrator.Admin'}</span></label>
          </div>
        </div>
        <div class="sm-setting">
          <div class="sm-setting-label">{l s='Speed' d='Modules.Shopmigrator.Admin'}</div>
          <div class="sm-segment" role="radiogroup">
            <label><input type="radio" name="sm-batch" value="25"><span>{l s='Careful (shared hosting)' d='Modules.Shopmigrator.Admin'}</span></label>
            <label><input type="radio" name="sm-batch" value="100" checked><span>{l s='Normal' d='Modules.Shopmigrator.Admin'}</span></label>
            <label><input type="radio" name="sm-batch" value="500"><span>{l s='Fast (dedicated server)' d='Modules.Shopmigrator.Admin'}</span></label>
          </div>
        </div>
        <div class="sm-setting">
          <div class="sm-setting-label">{l s='Options' d='Modules.Shopmigrator.Admin'}</div>
          <div class="sm-toggles">
            <label class="sm-toggle"><input type="checkbox" id="sm-addloc" checked><span class="sm-switch"></span><span>{l s='Add missing languages and currencies' d='Modules.Shopmigrator.Admin'}</span></label>
            <label class="sm-toggle"><input type="checkbox" id="sm-thumbs" checked><span class="sm-switch"></span><span>{l s='Build image thumbnails during the migration (untick for very large catalogs, then use Regenerate thumbnails in Design > Image Settings afterwards)' d='Modules.Shopmigrator.Admin'}</span></label>
          </div>
        </div>
      </div>

      <div class="sm-note sm-note-warn" id="sm-fresh-note">
        <i class="material-icons">warning_amber</i>
        <span>{l s='The selected data on this shop is deleted and replaced by the old shop’s data, with the same IDs, so links, order references and URLs stay the same. Back up this shop first. The old shop is never changed.' d='Modules.Shopmigrator.Admin'}</span>
      </div>
    </div>
    <footer class="sm-card-foot">
      <button type="button" class="sm-btn sm-btn-ghost" data-goto="1"><i class="material-icons">arrow_back</i> {l s='Back' d='Modules.Shopmigrator.Admin'}</button>
      <button type="button" class="sm-btn sm-btn-primary" id="sm-start"><i class="material-icons">play_arrow</i> {l s='Start migration' d='Modules.Shopmigrator.Admin'}</button>
    </footer>
  </section>

  {* Step 3: migrate *}
  <section class="sm-card sm-pane" data-pane="3" hidden>
    <div class="sm-hero">
      <div class="sm-hero-top">
        <div>
          <div class="sm-status" id="sm-status-pill"><span class="sm-pulse"></span><span id="sm-status">{l s='Migrating…' d='Modules.Shopmigrator.Admin'}</span></div>
          <div class="sm-pct"><span id="sm-pct">0</span><small>%</small></div>
        </div>
        <dl class="sm-kpis">
          <div><dt>{l s='Elapsed' d='Modules.Shopmigrator.Admin'}</dt><dd id="sm-elapsed">–</dd></div>
          <div><dt>{l s='Speed' d='Modules.Shopmigrator.Admin'}</dt><dd id="sm-speed">–</dd></div>
          <div><dt>{l s='Remaining' d='Modules.Shopmigrator.Admin'}</dt><dd id="sm-eta">–</dd></div>
        </dl>
      </div>
      <div class="sm-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" id="sm-meter"><div class="sm-meter-fill" id="sm-meter-fill"></div></div>
      <div class="sm-current" id="sm-current" aria-live="polite"></div>
    </div>
    <div class="sm-card-body">
      <ul class="sm-rows" id="sm-bars"></ul>
      <p class="sm-hint"><i class="material-icons">info</i> {l s='You can close this page: the migration pauses and continues from the same point when you come back.' d='Modules.Shopmigrator.Admin'}</p>
    </div>
    <footer class="sm-card-foot">
      <span></span>
      <span>
        <button type="button" class="sm-btn sm-btn-secondary" id="sm-pause"><i class="material-icons">pause</i> {l s='Pause' d='Modules.Shopmigrator.Admin'}</button>
        <button type="button" class="sm-btn sm-btn-primary" id="sm-resume" hidden><i class="material-icons">play_arrow</i> {l s='Resume' d='Modules.Shopmigrator.Admin'}</button>
      </span>
    </footer>
  </section>

  {* Step 4: report *}
  <section class="sm-card sm-pane" data-pane="4" hidden>
    <header class="sm-card-head sm-card-head-success">
      <div class="sm-icon"><i class="material-icons">task_alt</i></div>
      <div>
        <h2>{l s='Migration complete' d='Modules.Shopmigrator.Admin'}</h2>
        <p id="sm-summary-line"></p>
      </div>
    </header>
    <div class="sm-card-body">
      <div id="sm-summary" class="sm-stats"></div>
      <details class="sm-warn-box" id="sm-warn-box">
        <summary><i class="material-icons">report_problem</i> {l s='Warnings' d='Modules.Shopmigrator.Admin'} <span class="sm-badge" id="sm-warn-count">0</span></summary>
        <div id="sm-warnings" class="sm-warnings"></div>
      </details>
    </div>
    <footer class="sm-card-foot">
      <button type="button" class="sm-btn sm-btn-secondary" id="sm-download"><i class="material-icons">download</i> {l s='Download report' d='Modules.Shopmigrator.Admin'}</button>
      <button type="button" class="sm-btn sm-btn-ghost" id="sm-reset"><i class="material-icons">restart_alt</i> {l s='New migration' d='Modules.Shopmigrator.Admin'}</button>
    </footer>
  </section>
</div>
<script src="{$sm_js|escape:'html':'UTF-8'}"></script>
