<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
?>
<div id="sealdoc-admin" class="section">
	<h2><?php p($l->t('SealDoc')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Point this at the SealDoc instance you want to use. It can be the hosted service in the EU or your own self-hosted installation. Nothing is sent anywhere else.')); ?>
	</p>

	<p>
		<label for="sealdoc-base-url"><?php p($l->t('Server URL')); ?></label><br>
		<input type="url" id="sealdoc-base-url" placeholder="https://api.sealdoc.eu" style="width: 24em;">
	</p>

	<p>
		<label for="sealdoc-api-key"><?php p($l->t('API key')); ?></label><br>
		<input type="password" id="sealdoc-api-key" autocomplete="new-password" style="width: 24em;">
		<span id="sealdoc-key-state" class="sealdoc-muted"></span>
	</p>

	<p>
		<input type="checkbox" id="sealdoc-store-evidence" class="checkbox">
		<label for="sealdoc-store-evidence"><?php p($l->t('Also store the evidence pack')); ?></label>
	</p>

	<p>
		<label for="sealdoc-evidence-folder"><?php p($l->t('Evidence folder')); ?></label><br>
		<input type="text" id="sealdoc-evidence-folder" placeholder="/SealDoc evidence" style="width: 24em;">
		<span class="sealdoc-muted"><?php p($l->t('Packs are filed per year inside this folder.')); ?></span>
	</p>

	<p>
		<label for="sealdoc-retention"><?php p($l->t('Retention policy shown in the Seal panel')); ?></label><br>
		<input type="text" id="sealdoc-retention" style="width: 24em;"
		       placeholder="<?php p($l->t('for example: invoices are kept for 7 years')); ?>">
	</p>
	<p class="settings-hint">
		<?php p($l->t('Optional, and shown in the panel as this organisation own policy. SealDoc cannot determine statutory retention periods: those depend on what the document is and which jurisdiction it falls under, neither of which a sealing service can see. Leave it empty rather than guess.')); ?>
	</p>

	<p>
		<button id="sealdoc-save"><?php p($l->t('Save')); ?></button>
		<button id="sealdoc-test"><?php p($l->t('Test connection')); ?></button>
		<span id="sealdoc-result" class="sealdoc-muted"></span>
	</p>

	<div id="sealdoc-jobs" class="sealdoc-jobs" hidden>
		<h3><?php p($l->t('Background jobs')); ?></h3>
		<p id="sealdoc-jobs-mode" class="sealdoc-muted"></p>
		<p id="sealdoc-jobs-warning" class="sealdoc-alert" hidden></p>
	</div>

	<p class="settings-hint">
		<?php p($l->t('Sealing runs as a Flow action. Set up a rule under Administration settings, Flow: for example, seal every file created in a specific folder. Sealed files are written next to the original and appear a little later, because conversion and timestamping happen in the background.')); ?>
	</p>
</div>
