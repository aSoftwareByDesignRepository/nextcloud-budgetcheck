<?php
/** Synced from _shared/agent-auth — do not edit app-side. */

declare(strict_types=1);

/**
 * "AI access (MCP)" settings section — pack partial.
 *
 * Expects $agentAccess from AgentSettingsPayload::payload(). Renders
 * nothing when the user may neither mint nor see tokens (nav-gate, not a
 * disabled state). All IDs/classes use the app prefix (bc).
 *
 * @var \OCP\IL10N $l
 * @var array $agentAccess
 */

$agentAccess = is_array($agentAccess ?? null) ? $agentAccess : [];
if (($agentAccess['visible'] ?? false) !== true) {
	return;
}

$bc_state = [
	'appId' => (string)($agentAccess['appId'] ?? ''),
	'agentHeader' => (string)($agentAccess['agentHeader'] ?? ''),
	'ncUrl' => (string)($agentAccess['ncUrl'] ?? ''),
	'isAdmin' => (bool)($agentAccess['isAdmin'] ?? false),
	'canMint' => (bool)($agentAccess['canMint'] ?? false),
	'selfMintEnabled' => (bool)($agentAccess['selfMintEnabled'] ?? false),
	'tokens' => $agentAccess['tokens'] ?? [],
	'presets' => $agentAccess['presets'] ?? ['read' => [], 'act' => []],
	'allScopes' => $agentAccess['allScopes'] ?? [],
	'scopeLabels' => $agentAccess['scopeLabels'] ?? [],
];
$bc_tokens = is_array($bc_state['tokens']) ? $bc_state['tokens'] : [];
?>
<section class="bc-section bc-agent" id="bc-agent-access" aria-labelledby="bc-agent-h">
	<h2 class="bc-section-title" id="bc-agent-h"><?php p($l->t('AI access (MCP)')); ?></h2>
	<p class="bc-section-intro">
		<?php p($l->t('Connect an AI assistant to this app with a scoped token. The token works only for this app — never for your files, mail, or other apps.')); ?>
	</p>
	<div id="bc-agent-live" class="bc-visually-hidden" aria-live="polite"></div>

	<?php if ($bc_tokens === []): ?>
	<div class="bc-agent-empty" id="bc-agent-empty">
		<p class="bc-agent-empty__text"><?php p($l->t('No AI access yet.')); ?></p>
		<?php if ($bc_state['canMint']): ?>
		<button type="button" class="bc-btn bc-btn--primary" id="bc-agent-connect"><?php p($l->t('Connect your AI')); ?></button>
		<?php endif; ?>
	</div>
	<?php else: ?>
	<div class="bc-table-wrap bc-agent-tablewrap" tabindex="0" role="region" aria-labelledby="bc-agent-h">
		<table class="bc-table bc-table--cards bc-agent-table" id="bc-agent-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Label')); ?></th>
					<th scope="col"><?php p($l->t('Scopes')); ?></th>
					<th scope="col"><?php p($l->t('Created')); ?></th>
					<th scope="col"><?php p($l->t('Expires')); ?></th>
					<th scope="col"><?php p($l->t('Last used')); ?></th>
					<th scope="col"><span class="bc-visually-hidden"><?php p($l->t('Actions')); ?></span></th>
				</tr>
			</thead>
			<tbody id="bc-agent-tbody">
				<?php foreach ($bc_tokens as $bc_tok): ?>
				<tr data-token-id="<?php p((string)($bc_tok['id'] ?? '')); ?>" data-token-label="<?php p((string)($bc_tok['label'] ?? '')); ?>"<?php if (($bc_tok['state'] ?? '') !== 'active'): ?> class="bc-agent-row--inactive"<?php endif; ?>>
					<td data-cell="<?php p($l->t('Label')); ?>" class="bc-agent-label-cell"><span class="bc-agent-labelcell"><span class="bc-agent-label" title="<?php p((string)($bc_tok['label'] ?? '')); ?>"><?php p((string)($bc_tok['label'] ?? '')); ?></span>
						<?php if (($bc_tok['state'] ?? '') === 'revoked'): ?>
							<span class="bc-badge bc-badge--neutral"><?php p($l->t('Revoked')); ?></span>
						<?php elseif (($bc_tok['state'] ?? '') === 'expired'): ?>
							<span class="bc-badge bc-badge--neutral"><?php p($l->t('Expired')); ?></span>
						<?php else: ?>
							<span class="bc-badge bc-badge--info"><?php p($l->t('Active')); ?></span>
						<?php endif; ?>
						<?php if ($bc_state['isAdmin'] && ($bc_tok['userId'] ?? '') !== ''): ?>
							<span class="bc-agent-owner"><?php p((string)$bc_tok['userId']); ?></span>
						<?php endif; ?></span></td>
					<td data-cell="<?php p($l->t('Scopes')); ?>" class="bc-agent-scopes-cell">
						<span class="bc-agent-scopes">
						<?php foreach (($bc_tok['scopes'] ?? []) as $bc_scope): ?>
							<span class="bc-badge bc-badge--neutral bc-agent-scope" title="<?php p((string)$bc_scope); ?>"><?php p((string)($bc_state['scopeLabels'][$bc_scope] ?? $bc_scope)); ?></span>
						<?php endforeach; ?>
						</span>
					</td>
					<td data-cell="<?php p($l->t('Created')); ?>" data-ts="<?php p((string)($bc_tok['createdAt'] ?? '')); ?>"></td>
					<td data-cell="<?php p($l->t('Expires')); ?>" data-ts="<?php p((string)($bc_tok['expiresAt'] ?? '')); ?>"></td>
					<td data-cell="<?php p($l->t('Last used')); ?>"<?php if (($bc_tok['lastUsedAt'] ?? null) === null): ?> class="bc-agent-never"<?php endif; ?> data-ts="<?php p((string)($bc_tok['lastUsedAt'] ?? '')); ?>"><?php if (($bc_tok['lastUsedAt'] ?? null) === null): ?><?php p($l->t('never')); ?><?php endif; ?></td>
					<td class="bc-row-actions">
						<?php if (($bc_tok['state'] ?? '') === 'active'): ?>
						<button type="button" class="bc-btn bc-btn--ghost bc-agent-rotate" data-token-id="<?php p((string)$bc_tok['id']); ?>"><?php p($l->t('Rotate')); ?></button>
						<button type="button" class="bc-btn bc-btn--danger bc-agent-revoke" data-token-id="<?php p((string)$bc_tok['id']); ?>" data-token-label="<?php p((string)($bc_tok['label'] ?? '')); ?>"><?php p($l->t('Revoke')); ?></button>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ($bc_state['canMint']): ?>
		<div class="bc-agent-actions">
			<button type="button" class="bc-btn bc-btn--primary" id="bc-agent-connect"><?php p($l->t('Connect your AI')); ?></button>
		</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<div class="bc-agent-webhooks" id="bc-agent-webhooks">
		<h3 class="bc-agent-sub-h" id="bc-agent-webhooks-h"><?php p($l->t('Webhook endpoints')); ?></h3>
		<p class="bc-form-row__hint"><?php p($l->t('Your AI assistant can register endpoints that receive a signed notification whenever it changes something here. They belong to you — test or revoke them any time.')); ?></p>
		<div class="bc-table-wrap bc-agent-tablewrap" tabindex="0" role="region" aria-labelledby="bc-agent-webhooks-h">
			<table class="bc-table bc-table--cards bc-agent-table" id="bc-agent-webhook-table">
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Endpoint')); ?></th>
						<th scope="col"><?php p($l->t('Events')); ?></th>
						<th scope="col"><?php p($l->t('State')); ?></th>
						<th scope="col"><?php p($l->t('Last delivery')); ?></th>
						<th scope="col"><span class="bc-visually-hidden"><?php p($l->t('Actions')); ?></span></th>
					</tr>
				</thead>
				<tbody id="bc-agent-webhook-tbody"></tbody>
			</table>
		</div>
	</div>

	<?php if ($bc_state['isAdmin']): ?>
	<div class="bc-form-row bc-agent-selfmint">
		<input type="hidden" name="agent_self_mint" value="0">
		<label class="bc-checkbox-label" for="bc-agent-selfmint-cb">
			<input type="checkbox" id="bc-agent-selfmint-cb" <?php if ($bc_state['selfMintEnabled']): ?>checked<?php endif; ?>>
			<?php p($l->t('Let app users create their own AI tokens')); ?>
		</label>
		<p class="bc-form-row__hint" id="bc-agent-selfmint-hint"><?php p($l->t('When off, only app administrators can connect AI assistants.')); ?></p>
	</div>
	<?php endif; ?>

	<dialog id="bc-agent-mint-dialog" class="bc-dialog bc-agent-mint-dialog" aria-labelledby="bc-agent-mint-h">
		<div class="bc-dialog__body">
			<h3 id="bc-agent-mint-h"><?php p($l->t('Connect your AI')); ?></h3>
			<form id="bc-agent-mint-form" class="bc-form-stack" novalidate>
				<div class="bc-form-row">
					<label for="bc-agent-label"><?php p($l->t('Label')); ?></label>
					<input type="text" id="bc-agent-label" name="label" maxlength="190" required autocomplete="off" placeholder="<?php p($l->t('Claude on my laptop')); ?>">
					<p class="bc-field-error" id="bc-agent-label-error" hidden></p>
				</div>
				<fieldset class="bc-form-row bc-fieldset">
					<legend><?php p($l->t('What may the AI do?')); ?></legend>
					<div class="bc-choice-cards">
						<label class="bc-choice-card">
							<input type="radio" name="preset" value="read" checked>
							<span class="bc-choice-card__copy">
								<span class="bc-choice-card__title"><?php p($l->t('Read only')); ?></span>
								<span class="bc-choice-card__hint"><?php p($l->t('see your data, never change it')); ?></span>
							</span>
						</label>
						<label class="bc-choice-card">
							<input type="radio" name="preset" value="act">
							<span class="bc-choice-card__copy">
								<span class="bc-choice-card__title"><?php p($l->t('Read + act on my behalf')); ?></span>
								<span class="bc-choice-card__hint"><?php p($l->t('read and make changes — payments and legal stays with you')); ?></span>
							</span>
						</label>
						<label class="bc-choice-card">
							<input type="radio" name="preset" value="custom">
							<span class="bc-choice-card__copy">
								<span class="bc-choice-card__title"><?php p($l->t('Choose exactly')); ?></span>
								<span class="bc-choice-card__hint"><?php p($l->t('pick each permission yourself')); ?></span>
							</span>
						</label>
					</div>
				</fieldset>
				<fieldset class="bc-form-row bc-fieldset" id="bc-agent-custom-wrap" hidden>
					<legend><?php p($l->t('Permissions')); ?></legend>
					<div class="bc-agent-chips" id="bc-agent-chips"></div>
					<p class="bc-field-error" id="bc-agent-scopes-error" hidden></p>
				</fieldset>
				<p class="bc-form-row__hint"><?php p($l->t('Valid for 1 year. The token only reaches this app.')); ?></p>
			</form>
		</div>
		<footer class="bc-dialog__footer">
			<div class="bc-actions">
				<button type="button" class="bc-btn bc-btn--secondary" id="bc-agent-mint-cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="submit" form="bc-agent-mint-form" class="bc-btn bc-btn--primary" id="bc-agent-mint-submit"><?php p($l->t('Create token')); ?></button>
			</div>
		</footer>
	</dialog>

	<dialog id="bc-agent-secret-dialog" class="bc-dialog bc-agent-secret-dialog" aria-labelledby="bc-agent-secret-h">
		<div class="bc-dialog__body">
			<h3 id="bc-agent-secret-h"><?php p($l->t('Your AI is connected')); ?></h3>
			<p class="bc-form-row__hint"><?php p($l->t('Copy this config into your AI client now — the token is shown only once.')); ?></p>
			<div class="bc-form-row">
				<label for="bc-agent-secret"><?php p($l->t('Token')); ?></label>
				<div class="bc-input-group">
					<input type="password" id="bc-agent-secret" readonly autocomplete="off" spellcheck="false">
					<button type="button" class="bc-btn bc-btn--ghost" id="bc-agent-secret-reveal" aria-pressed="false"><?php p($l->t('Show')); ?></button>
					<button type="button" class="bc-btn bc-btn--ghost" id="bc-agent-secret-copy"><?php p($l->t('Copy')); ?></button>
				</div>
			</div>
			<div class="bc-form-row">
				<label for="bc-agent-config"><?php p($l->t('Client config')); ?></label>
				<textarea id="bc-agent-config" class="bc-agent-config" readonly rows="10" spellcheck="false"></textarea>
			</div>
		</div>
		<footer class="bc-dialog__footer">
			<div class="bc-actions">
				<button type="button" class="bc-btn bc-btn--secondary" id="bc-agent-secret-close"><?php p($l->t('Done')); ?></button>
				<button type="button" class="bc-btn bc-btn--primary" id="bc-agent-config-copy"><?php p($l->t('Copy config')); ?></button>
			</div>
		</footer>
	</dialog>

	<dialog id="bc-agent-revoke-dialog" class="bc-dialog" aria-labelledby="bc-agent-revoke-h">
		<div class="bc-dialog__body">
			<h3 id="bc-agent-revoke-h"><?php p($l->t('Revoke token?')); ?></h3>
			<p id="bc-agent-revoke-text"></p>
		</div>
		<footer class="bc-dialog__footer">
			<div class="bc-actions">
				<button type="button" class="bc-btn bc-btn--secondary" id="bc-agent-revoke-cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="button" class="bc-btn bc-btn--danger" id="bc-agent-revoke-confirm"><?php p($l->t('Revoke')); ?></button>
			</div>
		</footer>
	</dialog>

	<script type="application/json" id="bc-agent-state"><?php
		print_unescaped(json_encode($bc_state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
	?></script>
</section>
