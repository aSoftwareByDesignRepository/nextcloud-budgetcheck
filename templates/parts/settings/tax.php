<?php
/**
 * Workspace settings sub-page: Tax mode.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$canManage = !empty($_['canManageWorkspace']);
?>
<section class="bc-card bc-section" id="bc-tax-title" aria-label="<?php p($l->t('Tax mode')); ?>">
	<form class="bc-form-grid" data-bc-tax-form>
		<label class="bc-field bc-field--full-width bc-field--boolean">
			<span class="bc-field__label"><?php p($l->t('Workspace tax')); ?></span>
			<span class="bc-boolean-control">
				<input type="checkbox" name="taxModeEnabled" value="1" <?php p($canManage ? '' : 'disabled'); ?>>
				<span class="bc-boolean-control__text"><?php p($l->t('Enable tax mode for this workspace')); ?></span>
			</span>
			<span class="bc-field__hint"><?php p($l->t('When disabled, new entries are stored without net/VAT/gross split. Existing tax split values are removed automatically when you save disabled mode.')); ?></span>
		</label>
		<label class="bc-field">
			<span class="bc-field__label"><?php p($l->t('Budget basis')); ?></span>
			<select name="taxBudgetBasis" class="bc-input" <?php p($canManage ? '' : 'disabled'); ?>>
				<option value="gross"><?php p($l->t('Gross')); ?></option>
				<option value="net"><?php p($l->t('Net')); ?></option>
			</select>
			<span class="bc-field__hint"><?php p($l->t('This decides which value counts against budgets and project cap when a booking has tax split (net or gross entry).')); ?></span>
		</label>
		<label class="bc-field">
			<span class="bc-field__label"><?php p($l->t('Default VAT rate')); ?></span>
			<select name="vatPreset" class="bc-input" data-bc-vat-preset <?php p($canManage ? '' : 'disabled'); ?>>
				<option value="0"><?php p($l->t('0%% (none)')); ?></option>
				<option value="500"><?php p($l->t('5%%')); ?></option>
				<option value="700"><?php p($l->t('7%%')); ?></option>
				<option value="1000"><?php p($l->t('10%%')); ?></option>
				<option value="1300"><?php p($l->t('13%%')); ?></option>
				<option value="1500"><?php p($l->t('15%%')); ?></option>
				<option value="1900"><?php p($l->t('19%%')); ?></option>
				<option value="2000"><?php p($l->t('20%%')); ?></option>
				<option value="2100"><?php p($l->t('21%%')); ?></option>
				<option value="2500"><?php p($l->t('25%%')); ?></option>
				<option value="custom"><?php p($l->t('Custom…')); ?></option>
			</select>
			<span class="bc-field__hint"><?php p($l->t('Used as default in transaction dialogs when tax entry basis is net or gross.')); ?></span>
		</label>
		<label class="bc-field" data-bc-vat-custom-wrap hidden>
			<span class="bc-field__label"><?php p($l->t('Custom VAT (basis points)')); ?></span>
			<input type="number" min="0" max="5000" step="1" name="defaultVatRateBp" class="bc-input" data-bc-vat-custom <?php p($canManage ? '' : 'disabled'); ?>>
		</label>
		<p class="bc-tax-help bc-field__hint bc-field__hint--block" data-bc-tax-preview-summary>
			<?php p($l->t('If Budget basis is Net, budgets and project cap use net values. If Budget basis is Gross, they use gross values.')); ?>
		</p>
		<?php if ($canManage): ?>
			<div class="bc-form-actions">
				<button type="submit" class="button primary"><?php p($l->t('Save tax settings')); ?></button>
			</div>
		<?php endif; ?>
	</form>
</section>
