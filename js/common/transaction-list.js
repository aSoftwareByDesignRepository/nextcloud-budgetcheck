(function () {
	'use strict';

	const NOTE_PREVIEW_MAX = 120;

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

	window.BudgetCheckTransactionList = {
		recentListMeta,
		NOTE_PREVIEW_MAX,
	};
})();
