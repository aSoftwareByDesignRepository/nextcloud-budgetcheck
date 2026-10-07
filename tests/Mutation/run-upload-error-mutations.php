<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the 2026-10-07 upload-error contract:
 * rejected uploads must surface an actionable reason, never the generic
 * "action could not be completed" dead end.
 * Run: php tests/Mutation/run-upload-error-mutations.php
 */

$root = dirname(__DIR__, 2);
$failed = 0;

$assert = static function (bool $ok, string $label) use (&$failed): void {
	if ($ok) {
		fwrite(STDOUT, "killed {$label}\n");
		return;
	}
	fwrite(STDERR, "SURVIVED {$label}\n");
	$failed++;
};

$svc = (string) file_get_contents($root . '/lib/Service/TransactionAttachmentService.php');
$api = (string) file_get_contents($root . '/lib/Controller/ApiController.php');
$mobile = (string) file_get_contents($root . '/lib/Controller/MobileApiController.php');
$messaging = (string) file_get_contents($root . '/js/common/messaging.js');
$attachmentsJs = (string) file_get_contents($root . '/js/common/transaction-attachments.js');

// Service: every UPLOAD_ERR_* has a specific message — a single vague
// "try again" for all codes is the original defect.
$assert(str_contains($svc, 'describeUploadError'), 'upload_error_descriptor_present');
$assert(str_contains($svc, 'UPLOAD_ERR_INI_SIZE'), 'ini_size_branch_present');
$assert(str_contains($svc, 'UPLOAD_ERR_PARTIAL'), 'partial_branch_present');
$assert(str_contains($svc, 'UPLOAD_ERR_NO_FILE'), 'no_file_branch_present');
$assert(str_contains($svc, 'serverFault'), 'server_fault_distinction');
$assert(substr_count($svc, 'assertUploadSucceeded') >= 2, 'assert_used_in_upload_and_replace');
$assert(!str_contains($svc, 'File upload failed. Please try again.'), 'vague_message_removed');
// Malformed multi-file arrays must not collapse via (int) to INI_SIZE.
$assert((bool) preg_match('/is_array\(\$error\)/', $svc), 'array_error_rejected');

// Controllers: dropped multipart bodies (post_max_size) get the
// size-limit message; absent fields get "no file".
foreach (['api' => $api, 'mobile' => $mobile] as $name => $src) {
	$assert(str_contains($src, 'getUploadedFile'), "{$name}_uses_request_uploads");
	$assert(str_contains($src, 'missingUploadMessage'), "{$name}_missing_upload_helper");
	$assert(str_contains($src, 'multipart/form-data'), "{$name}_multipart_guard");
	$assert(str_contains($src, 'CONTENT_LENGTH'), "{$name}_content_length_probe");
	$assert(str_contains($src, 'maximum upload size configured on this server'), "{$name}_size_message");
}

// Client: web-server 413s and authored invalid_input messages reach the
// user; the passthrough stays bounded so stack data cannot leak.
$assert(str_contains($messaging, 'status === 413'), 'client_413_branch');
$assert(str_contains($messaging, "code === 'invalid_input'"), 'client_invalid_input_passthrough');
$assert(str_contains($messaging, 'message.length <= 300'), 'client_message_length_cap');
$assert(str_contains($attachmentsJs, 'lastError.status === 400'), 'pending_upload_reason_status');

// Regression net: contract tests pin the behaviour.
$assert(is_file($root . '/tests/Unit/Service/TransactionAttachmentUploadValidationTest.php'), 'upload_validation_test_present');
$assert(is_file($root . '/tests/js/messaging-handle-api-error.test.js'), 'messaging_error_test_present');

exit($failed === 0 ? 0 : 1);
