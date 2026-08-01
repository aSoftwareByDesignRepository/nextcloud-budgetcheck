<?php
/**
 * App settings sub-page: Support & us.
 *
 * Informational CTAs only; never gates AGPL use. No policy form.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$supportUsLanguageCode = method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en';
$supportUsCssPrefix = 'bc';
$supportUsBtnPrimaryClass = 'button primary';
$supportUsBtnSecondaryClass = 'button';
$supportUsLinks = new \OCA\BudgetCheck\Support\SupportUsLinks('BudgetCheck', false, null);
include dirname(__DIR__) . '/support-us-section.php';
