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
     data-t-nolang="{l s='Not installed here, its texts will be skipped:' d='Modules.Shopmigrator.Admin'}">

  <ol class="sm-steps">
    <li data-step="1">{l s='Connect' d='Modules.Shopmigrator.Admin'}</li>
    <li data-step="2">{l s='Choose data' d='Modules.Shopmigrator.Admin'}</li>
    <li data-step="3">{l s='Migrate' d='Modules.Shopmigrator.Admin'}</li>
    <li data-step="4">{l s='Report' d='Modules.Shopmigrator.Admin'}</li>
  </ol>

  {* Step 1 *}
  <div class="panel sm-pane" data-pane="1">
    <div class="panel-heading"><i class="icon-link"></i> {l s='Connect your old shop' d='Modules.Shopmigrator.Admin'}</div>
    <ol class="sm-howto">
      <li>{l s='Install the Shop Migrator Bridge module on your old shop (PrestaShop 1.6, 1.7, 8 or 9).' d='Modules.Shopmigrator.Admin'}</li>
      <li>{l s='Open its configuration page and copy the shop address and the key here.' d='Modules.Shopmigrator.Admin'}</li>
    </ol>
    <div class="form-group">
      <label>{l s='Old shop address' d='Modules.Shopmigrator.Admin'}</label>
      <input type="text" id="sm-url" class="form-control" value="{$sm_url|escape:'html':'UTF-8'}" placeholder="https://old-shop.com/">
    </div>
    <div class="form-group">
      <label>{l s='Connection key' d='Modules.Shopmigrator.Admin'}</label>
      <input type="text" id="sm-key" class="form-control" value="{$sm_key|escape:'html':'UTF-8'}" style="font-family:monospace">
    </div>
    <div id="sm-test-msg"></div>
    <div id="sm-info" class="sm-info" hidden></div>
    <div class="panel-footer">
      <button class="btn btn-default" id="sm-test"><i class="icon-plug"></i> {l s='Test connection' d='Modules.Shopmigrator.Admin'}</button>
      <button class="btn btn-primary pull-right" id="sm-next" disabled>{l s='Next' d='Modules.Shopmigrator.Admin'} <i class="icon-arrow-right"></i></button>
    </div>
  </div>

  {* Step 2 *}
  <div class="panel sm-pane" data-pane="2" hidden>
    <div class="panel-heading"><i class="icon-check-square-o"></i> {l s='Choose what to migrate' d='Modules.Shopmigrator.Admin'}</div>
    <div id="sm-typelist" class="sm-types"></div>
    <div class="form-group" style="margin-top:16px">
      <label>{l s='Speed' d='Modules.Shopmigrator.Admin'}</label>
      <select id="sm-batch" class="form-control" style="max-width:340px">
        <option value="25">{l s='Careful (shared hosting)' d='Modules.Shopmigrator.Admin'}</option>
        <option value="100" selected>{l s='Normal' d='Modules.Shopmigrator.Admin'}</option>
        <option value="500">{l s='Fast (dedicated server)' d='Modules.Shopmigrator.Admin'}</option>
      </select>
    </div>
    <div class="checkbox">
      <label><input type="checkbox" id="sm-thumbs" checked> {l s='Build image thumbnails during the migration (untick for very large catalogs, then use Regenerate thumbnails in Design > Image Settings afterwards)' d='Modules.Shopmigrator.Admin'}</label>
    </div>
    <div class="form-group">
      <label>{l s='Mode' d='Modules.Shopmigrator.Admin'}</label>
      <div class="radio"><label><input type="radio" name="sm-mode" value="fresh" checked> {l s='Fresh copy: replace the selected data on this shop' d='Modules.Shopmigrator.Admin'}</label></div>
      <div class="radio"><label><input type="radio" name="sm-mode" value="new"> {l s='New data only: add what was created on the old shop since the last migration (new orders, customers, products…), keep everything here' d='Modules.Shopmigrator.Admin'}</label></div>
    </div>
    <div class="alert alert-warning" id="sm-fresh-note">
      <strong>{l s='Fresh copy, IDs kept.' d='Modules.Shopmigrator.Admin'}</strong>
      {l s='The selected data on this shop is deleted and replaced by the old shop’s data, with the same IDs, so links, order references and URLs stay the same. Back up this shop first. The old shop is never changed.' d='Modules.Shopmigrator.Admin'}
    </div>
    <div class="panel-footer">
      <button class="btn btn-default" data-goto="1"><i class="icon-arrow-left"></i> {l s='Back' d='Modules.Shopmigrator.Admin'}</button>
      <button class="btn btn-primary pull-right" id="sm-start"><i class="icon-play"></i> {l s='Start migration' d='Modules.Shopmigrator.Admin'}</button>
    </div>
  </div>

  {* Step 3 *}
  <div class="panel sm-pane" data-pane="3" hidden>
    <div class="panel-heading"><i class="icon-refresh"></i> <span id="sm-status">{l s='Migrating…' d='Modules.Shopmigrator.Admin'}</span></div>
    <div id="sm-bars"></div>
    <p class="sm-current" id="sm-current"></p>
    <p class="help-block">{l s='You can close this page: the migration pauses and continues from the same point when you come back.' d='Modules.Shopmigrator.Admin'}</p>
    <div class="panel-footer">
      <button class="btn btn-default" id="sm-pause"><i class="icon-pause"></i> {l s='Pause' d='Modules.Shopmigrator.Admin'}</button>
      <button class="btn btn-primary" id="sm-resume" hidden><i class="icon-play"></i> {l s='Resume' d='Modules.Shopmigrator.Admin'}</button>
    </div>
  </div>

  {* Step 4 *}
  <div class="panel sm-pane" data-pane="4" hidden>
    <div class="panel-heading"><i class="icon-flag-checkered"></i> {l s='Report' d='Modules.Shopmigrator.Admin'}</div>
    <div id="sm-summary"></div>
    <h4 id="sm-warn-title">{l s='Warnings' d='Modules.Shopmigrator.Admin'} <span class="badge" id="sm-warn-count">0</span></h4>
    <div id="sm-warnings" class="sm-warnings"></div>
    <div class="panel-footer">
      <button class="btn btn-default" id="sm-download"><i class="icon-download"></i> {l s='Download report' d='Modules.Shopmigrator.Admin'}</button>
      <button class="btn btn-default pull-right" id="sm-reset"><i class="icon-refresh"></i> {l s='New migration' d='Modules.Shopmigrator.Admin'}</button>
    </div>
  </div>
</div>
<script src="{$sm_js|escape:'html':'UTF-8'}"></script>
