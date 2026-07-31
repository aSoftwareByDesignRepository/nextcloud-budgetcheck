<?php

declare(strict_types=1);

return [
	'routes' => [
		// Page (HTML) routes — workspace-scoped via ?workspaceId= query param.
		// Type-aware redirects are enforced server-side (see PageController).
		['name' => 'page#index',        'url' => '/',             'verb' => 'GET'],
		['name' => 'page#dashboard',    'url' => '/dashboard',    'verb' => 'GET'],
		['name' => 'page#transactions', 'url' => '/transactions', 'verb' => 'GET'],
		['name' => 'page#import',       'url' => '/import',       'verb' => 'GET'],
		['name' => 'page#budgets',      'url' => '/budgets',      'verb' => 'GET'],
		['name' => 'page#monthly',      'url' => '/monthly',      'verb' => 'GET'],
		['name' => 'page#period',       'url' => '/period',       'verb' => 'GET'],
		['name' => 'page#yearly',       'url' => '/yearly',       'verb' => 'GET'],
		['name' => 'page#workspaceOverview', 'url' => '/workspaces', 'verb' => 'GET'],
		['name' => 'page#settings',     'url' => '/settings',     'verb' => 'GET'],
		['name' => 'page#appSettings',  'url' => '/app-settings', 'verb' => 'GET'],
		['name' => 'export#householdYearly', 'url' => '/export/household-yearly', 'verb' => 'GET'],
		['name' => 'export#projectPeriod', 'url' => '/export/project-period', 'verb' => 'GET'],

		// JSON workspace + member routes
		['name' => 'api#listWorkspaces',     'url' => '/api/workspaces',                   'verb' => 'GET'],
		['name' => 'api#createWorkspace',    'url' => '/api/workspaces',                   'verb' => 'POST'],
		['name' => 'api#getWorkspace',       'url' => '/api/workspaces/{id}',              'verb' => 'GET'],
		['name' => 'api#updateWorkspace',    'url' => '/api/workspaces/{id}',              'verb' => 'PUT'],
		['name' => 'api#updateTaxMode',      'url' => '/api/workspaces/{id}/tax-mode',     'verb' => 'PUT'],
		['name' => 'api#getWorkspaceFavorites', 'url' => '/api/workspace-favorites', 'verb' => 'GET'],
		['name' => 'api#saveWorkspaceFavorites', 'url' => '/api/workspace-favorites', 'verb' => 'PUT'],
		['name' => 'api#listBookingStatuses', 'url' => '/api/booking-statuses', 'verb' => 'GET'],
		['name' => 'api#createBookingStatus', 'url' => '/api/booking-statuses', 'verb' => 'POST'],
		['name' => 'api#updateBookingStatus', 'url' => '/api/booking-statuses/{id}', 'verb' => 'PUT'],
		['name' => 'api#deactivateBookingStatus', 'url' => '/api/booking-statuses/{id}/deactivate', 'verb' => 'POST'],
		['name' => 'api#listMembers',        'url' => '/api/workspaces/{id}/members',      'verb' => 'GET'],
		['name' => 'api#addMember',          'url' => '/api/workspaces/{id}/members',      'verb' => 'POST'],
		['name' => 'api#updateMember',       'url' => '/api/workspace-members/{id}',       'verb' => 'PUT'],
		['name' => 'api#removeMember',       'url' => '/api/workspace-members/{id}',       'verb' => 'DELETE'],
		['name' => 'api#addGroupMember',     'url' => '/api/workspaces/{id}/group-members', 'verb' => 'POST'],
		['name' => 'api#updateGroupMember',  'url' => '/api/workspace-group-members/{id}',  'verb' => 'PUT'],
		['name' => 'api#removeGroupMember',  'url' => '/api/workspace-group-members/{id}',  'verb' => 'DELETE'],

		// Categories
		['name' => 'api#listCategories',     'url' => '/api/categories',                   'verb' => 'GET'],
		['name' => 'api#createCategory',     'url' => '/api/categories',                   'verb' => 'POST'],
		['name' => 'api#updateCategory',     'url' => '/api/categories/{id}',              'verb' => 'PUT'],
		['name' => 'api#deactivateCategory', 'url' => '/api/categories/{id}/deactivate',   'verb' => 'POST'],

		// Transactions
		['name' => 'api#listTransactions',   'url' => '/api/transactions',                 'verb' => 'GET'],
		['name' => 'api#createTransaction',  'url' => '/api/transactions',                 'verb' => 'POST'],
		['name' => 'api#previewTransactionImport', 'url' => '/api/transactions/import/preview', 'verb' => 'POST'],
		['name' => 'api#commitTransactionImport', 'url' => '/api/transactions/import/commit', 'verb' => 'POST'],
		['name' => 'api#getImportPreferences', 'url' => '/api/workspaces/{workspaceId}/import-preferences', 'verb' => 'GET'],
		['name' => 'api#saveImportPreferences', 'url' => '/api/workspaces/{workspaceId}/import-preferences', 'verb' => 'PUT'],
		['name' => 'api#getSummaryViewPreferences', 'url' => '/api/workspaces/{workspaceId}/summary-view-preferences', 'verb' => 'GET'],
		['name' => 'api#saveSummaryViewPreferences', 'url' => '/api/workspaces/{workspaceId}/summary-view-preferences', 'verb' => 'PUT'],
		['name' => 'api#updateTransaction',  'url' => '/api/transactions/{id}',            'verb' => 'PUT'],
		['name' => 'api#deleteTransaction',  'url' => '/api/transactions/{id}',            'verb' => 'DELETE'],
		['name' => 'api#listTransactionAttachments', 'url' => '/api/transactions/{transactionId}/attachments', 'verb' => 'GET'],
		['name' => 'api#uploadTransactionAttachment', 'url' => '/api/transactions/{transactionId}/attachments', 'verb' => 'POST'],
		['name' => 'api#deleteTransactionAttachment', 'url' => '/api/transaction-attachments/{id}', 'verb' => 'DELETE'],
		['name' => 'api#replaceTransactionAttachment', 'url' => '/api/transaction-attachments/{id}/replace', 'verb' => 'POST'],
		['name' => 'attachment#download', 'url' => '/api/transaction-attachments/{id}', 'verb' => 'GET'],

		// Recurring rules
		['name' => 'api#listRecurringRules',     'url' => '/api/recurring-rules',                  'verb' => 'GET'],
		['name' => 'api#createRecurringRule',    'url' => '/api/recurring-rules',                  'verb' => 'POST'],
		['name' => 'api#updateRecurringRule',    'url' => '/api/recurring-rules/{id}',             'verb' => 'PUT'],
		['name' => 'api#deleteRecurringRule',    'url' => '/api/recurring-rules/{id}',             'verb' => 'DELETE'],
		['name' => 'api#generateFromRecurringRule', 'url' => '/api/recurring-rules/{id}/generate', 'verb' => 'POST'],

		// Budgets and savings
		['name' => 'api#listBudgets',        'url' => '/api/budgets',                       'verb' => 'GET'],
		['name' => 'api#bulkUpsertBudgets',  'url' => '/api/budgets/bulk-upsert',           'verb' => 'POST'],
		['name' => 'api#generatePlannedFromBudgets', 'url' => '/api/budgets/generate-planned', 'verb' => 'POST'],
		['name' => 'api#listBudgetDefaults', 'url' => '/api/budget-defaults',               'verb' => 'GET'],
		['name' => 'api#bulkUpsertBudgetDefaults', 'url' => '/api/budget-defaults/bulk-upsert', 'verb' => 'POST'],
		['name' => 'api#getSavingsTarget',   'url' => '/api/savings-target',                'verb' => 'GET'],
		['name' => 'api#saveSavingsTarget',  'url' => '/api/savings-target',                'verb' => 'POST'],

		// Summaries
		['name' => 'api#monthlySummary',     'url' => '/api/monthly-summary',               'verb' => 'GET'],
		['name' => 'api#monthlyClose',       'url' => '/api/monthly-close',                 'verb' => 'POST'],
		['name' => 'api#monthlyReopen',      'url' => '/api/monthly-reopen',                'verb' => 'POST'],
		['name' => 'api#yearlySummary',      'url' => '/api/yearly-summary',                'verb' => 'GET'],
		['name' => 'api#projectPeriodSummary', 'url' => '/api/project-period-summary',      'verb' => 'GET'],

		// Admin directory lookup for member assignment (manager+ scoped)
		['name' => 'api#searchUsers',        'url' => '/api/admin/users',                   'verb' => 'GET'],
		['name' => 'api#searchGroups',       'url' => '/api/admin/groups',                  'verb' => 'GET'],

		// App-wide policy (admins): app admin uids, default tz/currency
		['name' => 'api#getAppPolicy',       'url' => '/api/admin/policy',                  'verb' => 'GET'],
		['name' => 'api#saveAppPolicy',      'url' => '/api/admin/policy',                  'verb' => 'POST'],

		// Free BudgetCheck Mobile companion API (COMPANION-APP.md v2.0 — no license gates)
		['name' => 'mobile_api#bootstrap', 'url' => '/api/mobile/v1/bootstrap', 'verb' => 'GET'],
		['name' => 'mobile_api#listWorkspaces', 'url' => '/api/mobile/v1/workspaces', 'verb' => 'GET'],
		['name' => 'mobile_api#createWorkspace', 'url' => '/api/mobile/v1/workspaces', 'verb' => 'POST'],
		['name' => 'mobile_api#home', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/home', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\\d+']],
		['name' => 'mobile_api#monthlySummary', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/monthly-summary', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\\d+']],
		['name' => 'mobile_api#yearlySummary', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/yearly-summary', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\\d+']],
		['name' => 'mobile_api#periodSummary', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/period-summary', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\\d+']],
		['name' => 'mobile_api#listCategories', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/categories', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\\d+']],
		['name' => 'mobile_api#listBookingStatuses', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/booking-statuses', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\\d+']],
		['name' => 'mobile_api#listTransactions', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/transactions', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\\d+']],
		['name' => 'mobile_api#getTransaction', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/transactions/{txId}', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\\d+', 'txId' => '\\d+']],
		['name' => 'mobile_api#createTransaction', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/transactions', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\\d+']],
		['name' => 'mobile_api#updateTransaction', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/transactions/{txId}', 'verb' => 'PUT', 'requirements' => ['workspaceId' => '\\d+', 'txId' => '\\d+']],
		['name' => 'mobile_api#deleteTransaction', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/transactions/{txId}', 'verb' => 'DELETE', 'requirements' => ['workspaceId' => '\\d+', 'txId' => '\\d+']],
		['name' => 'mobile_api#listRecurringSuggestions', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/recurring-suggestions', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\\d+']],
		['name' => 'mobile_api#applyRecurringSuggestion', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/recurring-suggestions/{ruleId}/apply', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\\d+', 'ruleId' => '\\d+']],
		['name' => 'mobile_api#registerPushToken', 'url' => '/api/mobile/v1/device/push-token', 'verb' => 'POST'],
		['name' => 'mobile_api#unregisterPushToken', 'url' => '/api/mobile/v1/device/push-token', 'verb' => 'DELETE'],
		['name' => 'mobile_api#listTransactionAttachments', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/transactions/{txId}/attachments', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\\d+', 'txId' => '\\d+']],
		['name' => 'mobile_api#uploadTransactionAttachment', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/transactions/{txId}/attachments', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\\d+', 'txId' => '\\d+']],
		['name' => 'mobile_api#deleteTransactionAttachment', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/attachments/{attachmentId}', 'verb' => 'DELETE', 'requirements' => ['workspaceId' => '\\d+', 'attachmentId' => '\\d+']],
		['name' => 'mobile_api#downloadTransactionAttachment', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/attachments/{attachmentId}/download', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\\d+', 'attachmentId' => '\\d+']],
	],
];
