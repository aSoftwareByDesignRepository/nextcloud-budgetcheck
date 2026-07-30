(function () {
	'use strict';

	const NOTE_PREVIEW_MAX = 120;

	function hasReceipts(tx) {
		if (!tx) return false;
		return !!(tx.hasAttachments || (Number(tx.attachmentCount) > 0));
	}

	function receiptLabel(tx) {
		const count = Number(tx && tx.attachmentCount) || 0;
		if (count <= 0 && !tx?.hasAttachments) return '';
		const effective = count > 0 ? count : 1;
		return effective === 1
			? t('budgetcheck', '1 receipt attached')
			: t('budgetcheck', '{count} receipts attached').replace('{count}', String(effective));
	}

	/**
	 * Secondary line for compact transaction lists (dashboard recent entries).
	 * Shows booking date and note preview; direction is conveyed by amount styling.
	 *
	 * @param {{ bookingDate?: string, notes?: string|null }} tx
	 * @param {string} htmlLang
	 * @return {{ text: string, fullNote: string|null }}
	 */
	function recentListMeta(tx, htmlLang) {
		const Dates = window.BudgetCheckDates;
		const date = Dates && tx.bookingDate
			? Dates.formatDisplayDate(tx.bookingDate, htmlLang)
			: '';
		const rawNote = typeof tx.notes === 'string' ? tx.notes.trim().replace(/\s+/gu, ' ') : '';
		if (rawNote === '') {
			return { text: date, fullNote: null };
		}
		const truncated = rawNote.length > NOTE_PREVIEW_MAX;
		const preview = truncated ? rawNote.slice(0, NOTE_PREVIEW_MAX - 1) + '…' : rawNote;
		return {
			text: date ? date + ' · ' + preview : preview,
			fullNote: truncated ? rawNote : null,
		};
	}

	function applyReceiptCount(tx, count) {
		if (!tx) return tx;
		const n = Math.max(0, Number(count) || 0);
		tx.attachmentCount = n;
		tx.hasAttachments = n > 0;
		return tx;
	}

	if (!window.BudgetCheck || typeof window.BudgetCheck.define !== 'function') {
		throw new Error('BudgetCheck bootstrap missing — TransactionList cannot register');
	}
	window.BudgetCheck.define('TransactionList', {
		recentListMeta,
		hasReceipts,
		receiptLabel,
		applyReceiptCount,
		NOTE_PREVIEW_MAX,
	});
})();
