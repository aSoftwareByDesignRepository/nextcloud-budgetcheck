<?php
/**
 * Settings sub-page: Transfer configuration (test → production).
 * Two cards — Export (passphrase → .nctransfer download) and Import
 * (mode → file → passphrase → mandatory dry-run → confirm) — plus the
 * "Last transfer" receipt line. The report dialog is a native <dialog>.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$isSystemAdmin = !empty($_['isSystemAdmin']);
$lastReceipt = is_array($_['transferLastReceipt'] ?? null) ? $_['transferLastReceipt'] : null;
?>
<section class="bc-card bc-section" aria-labelledby="bc-xfer-export-h" id="bc-transfer-export-card">
	<header class="bc-section__header">
		<div>
			<h2 id="bc-xfer-export-h"><?php p($l->t('Export configuration')); ?></h2>
			<p class="bc-section__sub"><?php p($l->t('Download all settings, catalogues and business data as one encrypted file — then import it on your production instance.')); ?></p>
		</div>
	</header>
	<div class="bc-callout bc-callout--info" role="note">
		<p><strong><?php p($l->t('Secrets are never included.')); ?></strong>
		<?php p($l->t('Passwords, tokens and salts stay on this instance — the file is encrypted and still does not carry them. It does contain user and group names: handle it like personal data.')); ?></p>
	</div>
	<form id="bc-transfer-export-form" class="bc-form" novalidate>
		<div class="bc-form-row">
			<label for="bc-transfer-passphrase"><?php p($l->t('Encryption passphrase')); ?></label>
			<div class="bc-input-group">
				<input type="password" id="bc-transfer-passphrase" name="passphrase" autocomplete="new-password" required minlength="12" aria-describedby="bc-transfer-passphrase-hint bc-transfer-passphrase-strength">
				<button type="button" class="bc-btn bc-btn--ghost" id="bc-transfer-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
				<button type="button" class="bc-btn bc-btn--secondary" id="bc-transfer-passphrase-generate"><?php p($l->t('Generate')); ?></button>
			</div>
			<p class="bc-form-row__hint" id="bc-transfer-passphrase-hint"><?php p($l->t('At least 12 characters. Without this passphrase the file cannot be restored — store it safely.')); ?></p>
			<p class="bc-form-row__hint bc-transfer-strength" id="bc-transfer-passphrase-strength" role="status" aria-live="polite"></p>
		</div>
		<div class="bc-actions">
			<button type="submit" class="bc-btn bc-btn--primary" id="bc-transfer-export-btn">
				<?php p($l->t('Download encrypted transfer file')); ?>
			</button>
		</div>
	</form>
</section>

<section class="bc-card bc-section" aria-labelledby="bc-xfer-import-h" id="bc-transfer-import-card">
	<header class="bc-section__header">
		<div>
			<h2 id="bc-xfer-import-h"><?php p($l->t('Import configuration')); ?></h2>
			<p class="bc-section__sub"><?php p($l->t('Pick a transfer file, enter its passphrase, review the preview — then confirm. Nothing is written before you confirm.')); ?></p>
		</div>
	</header>
	<?php if ($lastReceipt !== null): ?>
	<p class="bc-transfer-last" id="bc-transfer-last">
		<?php p($l->t('Last transfer')); ?>:
		<strong><?php p((string)($lastReceipt['at'] ?? '—')); ?></strong>
		· <?php p((string)($lastReceipt['mode'] ?? 'merge')); ?>
		· <?php p(($lastReceipt['result'] ?? '') === 'applied' ? $l->t('applied') : $l->t('failed')); ?>
		<?php if (($lastReceipt['snapshotId'] ?? null) !== null): ?>
			· <?php p($l->t('undo via snapshot')); ?> <code><?php p((string)$lastReceipt['snapshotId']); ?></code>
		<?php endif; ?>
	</p>
	<?php endif; ?>
	<form id="bc-transfer-import-form" class="bc-form" novalidate>
		<fieldset class="bc-form-row bc-fieldset">
			<legend><?php p($l->t('Mode')); ?></legend>
			<label class="bc-radio">
				<input type="radio" name="mode" value="merge" checked>
				<span><strong><?php p($l->t('Merge')); ?></strong> — <?php p($l->t('add and update; nothing is deleted')); ?></span>
			</label>
			<label class="bc-radio bc-radio--danger">
				<input type="radio" name="mode" value="replace" <?php if (!$isSystemAdmin): ?>disabled<?php endif; ?>>
				<span><strong><?php p($l->t('Replace')); ?></strong> — <?php p($l->t('make identical to the file; prod-only rows are deleted')); ?></span>
			</label>
			<p class="bc-form-row__hint" id="bc-transfer-replace-hint">
				<?php if ($isSystemAdmin): ?>
					<?php p($l->t('Replace needs maintenance mode ON (occ maintenance:mode --on) — you will type REPLACE in the preview to confirm.')); ?>
				<?php else: ?>
					<?php p($l->t('Replace is available to Nextcloud system administrators only.')); ?>
				<?php endif; ?>
			</p>
		</fieldset>
		<div class="bc-form-row">
			<label for="bc-transfer-file"><?php p($l->t('Transfer file')); ?></label>
			<input type="file" id="bc-transfer-file" name="file" accept=".nctransfer,application/json" class="bc-file-input">
		</div>
		<div class="bc-form-row">
			<label for="bc-transfer-import-passphrase"><?php p($l->t('File passphrase')); ?></label>
			<div class="bc-input-group">
				<input type="password" id="bc-transfer-import-passphrase" name="import_passphrase" autocomplete="off">
				<button type="button" class="bc-btn bc-btn--ghost" id="bc-transfer-import-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
			</div>
		</div>
		<div id="bc-transfer-import-status" class="bc-form-row__hint" role="status" aria-live="polite"></div>
	</form>
</section>

<dialog id="bc-transfer-report" class="bc-dialog bc-transfer-report" aria-labelledby="bc-transfer-report-h">
	<div class="bc-dialog__body">
		<h3 id="bc-transfer-report-h"><?php p($l->t('Import preview')); ?></h3>
		<p id="bc-transfer-report-verdict" class="bc-transfer-report__verdict"></p>
		<p id="bc-transfer-report-file" class="bc-form-row__hint"></p>
		<div id="bc-transfer-report-groups"></div>
	</div>
	<footer class="bc-dialog__footer">
		<div id="bc-transfer-replace-confirm" class="bc-form-row" hidden>
			<label for="bc-transfer-replace-token"><?php p($l->t('Type REPLACE to confirm')); ?></label>
			<input type="text" id="bc-transfer-replace-token" autocomplete="off" spellcheck="false" placeholder="REPLACE">
		</div>
		<div class="bc-actions">
			<button type="button" class="bc-btn bc-btn--secondary" id="bc-transfer-cancel" value="cancel"><?php p($l->t('Cancel')); ?></button>
			<button type="button" class="bc-btn bc-btn--primary" id="bc-transfer-confirm"><?php p($l->t('Apply import')); ?></button>
		</div>
	</footer>
</dialog>

<dialog id="bc-transfer-progress" class="bc-dialog" aria-labelledby="bc-transfer-progress-h" aria-live="polite">
	<div class="bc-dialog__body">
		<h3 id="bc-transfer-progress-h"><?php p($l->t('Applying import')); ?></h3>
		<progress id="bc-transfer-progress-bar" max="100" value="0"></progress>
		<p id="bc-transfer-progress-text"><?php p($l->t('Starting…')); ?></p>
		<p class="bc-form-row__hint"><?php p($l->t('You can close this — the result is saved and shown under “Last transfer”.')); ?></p>
	</div>
</dialog>

<div id="bc-transfer-result-live" class="bc-visually-hidden" role="status" aria-live="polite"></div>
