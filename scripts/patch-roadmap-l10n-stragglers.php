#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Catch-all stragglers + zh_CN placeholder-order fix. Brand keys (BudgetCheck,
 * InvoiceCheck, percent presets) intentionally left identical. */

$base = __DIR__ . '/../l10n';

$maps = [
	'de' => [
		'Budgets' => 'Budgets', 'Info' => 'Info', 'Notifications' => 'Benachrichtigungen',
		'Standard' => 'Standard', 'Type' => 'Typ', 'Match' => 'Treffer', 'Status' => 'Status',
		'Alert' => 'Hinweis', 'gone' => 'weg', 'new' => 'neu', 'Name' => 'Name',
		'Optional:' => 'Optional:', 'Check Partner' => 'Partner-Check',
		'BudgetCheck alert' => 'BudgetCheck-Hinweis', '{count} budgets' => '{count} Budgets',
	],
	'fr' => [
		'Budgets' => 'Budgets', 'Info' => 'Info', 'Notifications' => 'Notifications',
		'Standard' => 'Standard', 'Type' => 'Type', 'Match' => 'Correspondance', 'Status' => 'Statut',
		'Alert' => 'Alerte', 'gone' => 'disparu', 'new' => 'nouveau', 'Name' => 'Nom',
		'Optional:' => 'Optionnel :', 'Check Partner' => 'Voir le partenaire',
		'BudgetCheck alert' => 'Alerte BudgetCheck', '{count} budgets' => '{count} budgets',
	],
	'es' => [
		'Budgets' => 'Presupuestos', 'Info' => 'Información', 'Notifications' => 'Notificaciones',
		'Standard' => 'Estándar', 'Type' => 'Tipo', 'Match' => 'Coincidencia', 'Status' => 'Estado',
		'Alert' => 'Aviso', 'gone' => 'desaparecido', 'new' => 'nuevo', 'Name' => 'Nombre',
		'Optional:' => 'Opcional:', 'Check Partner' => 'Ver socio',
		'BudgetCheck alert' => 'Aviso de BudgetCheck', '{count} budgets' => '{count} presupuestos',
	],
	'da' => [
		'Budgets' => 'Budgetter', 'Info' => 'Info', 'Notifications' => 'Notifikationer',
		'Standard' => 'Standard', 'Type' => 'Type', 'Match' => 'Match', 'Status' => 'Status',
		'Alert' => 'Påmindelse', 'gone' => 'væk', 'new' => 'ny', 'Name' => 'Navn',
		'Optional:' => 'Valgfrit:', 'Check Partner' => 'Se partner',
		'BudgetCheck alert' => 'BudgetCheck-påmindelse', '{count} budgets' => '{count} budgetter',
	],
	'nl' => [
		'Budgets' => 'Budgetten', 'Info' => 'Info', 'Notifications' => 'Notificaties',
		'Standard' => 'Standaard', 'Type' => 'Type', 'Match' => 'Vergelijking', 'Status' => 'Status',
		'Alert' => 'Melding', 'gone' => 'verdwenen', 'new' => 'nieuw', 'Name' => 'Naam',
		'Optional:' => 'Optioneel:', 'Check Partner' => 'Naar partner',
		'BudgetCheck alert' => 'BudgetCheck-melding', '{count} budgets' => '{count} budgetten',
	],
	'it' => [
		'Budgets' => 'Budget', 'Info' => 'Informazioni', 'Notifications' => 'Notifiche',
		'Standard' => 'Standard', 'Type' => 'Tipo', 'Match' => 'Corrispondenza', 'Status' => 'Stato',
		'Alert' => 'Avviso', 'gone' => 'sparito', 'new' => 'nuovo', 'Name' => 'Nome',
		'Optional:' => 'Facoltativo:', 'Check Partner' => 'Vai al partner',
		'BudgetCheck alert' => 'Avviso BudgetCheck', '{count} budgets' => '{count} budget',
	],
	'pl' => [
		'Budgets' => 'Budżety', 'Info' => 'Informacje', 'Notifications' => 'Powiadomienia',
		'Standard' => 'Standard', 'Type' => 'Typ', 'Match' => 'Dopasowanie', 'Status' => 'Stan',
		'Alert' => 'Alert', 'gone' => 'zniknięta', 'new' => 'nowa', 'Name' => 'Nazwa',
		'Optional:' => 'Opcjonalne:', 'Check Partner' => 'Przejdź do partnera',
		'BudgetCheck alert' => 'Alert BudgetCheck', '{count} budgets' => '{count} budżetów',
	],
	'sv' => [
		'Budgets' => 'Budgetar', 'Info' => 'Info', 'Notifications' => 'Notiser',
		'Standard' => 'Standard', 'Type' => 'Typ', 'Match' => 'Matchning', 'Status' => 'Status',
		'Alert' => 'Avisering', 'gone' => 'borta', 'new' => 'ny', 'Name' => 'Namn',
		'Optional:' => 'Valfritt:', 'Check Partner' => 'Visa partner',
		'BudgetCheck alert' => 'BudgetCheck-avisering', '{count} budgets' => '{count} budgetar',
	],
	'nb' => [
		'Budgets' => 'Budsjetter', 'Info' => 'Info', 'Notifications' => 'Varsler',
		'Standard' => 'Standard', 'Type' => 'Type', 'Match' => 'Match', 'Status' => 'Status',
		'Alert' => 'Varsel', 'gone' => 'borte', 'new' => 'ny', 'Name' => 'Navn',
		'Optional:' => 'Valgfritt:', 'Check Partner' => 'Se partner',
		'BudgetCheck alert' => 'BudgetCheck-varsel', '{count} budgets' => '{count} budsjetter',
	],
	'pt' => [
		'Budgets' => 'Orçamentos', 'Info' => 'Informação', 'Notifications' => 'Notificações',
		'Standard' => 'Padrão', 'Type' => 'Tipo', 'Match' => 'Correspondência', 'Status' => 'Estado',
		'Alert' => 'Alerta', 'gone' => 'desaparecida', 'new' => 'nova', 'Name' => 'Nome',
		'Optional:' => 'Opcional:', 'Check Partner' => 'Ver parceiro',
		'BudgetCheck alert' => 'Alerta do BudgetCheck', '{count} budgets' => '{count} orçamentos',
	],
	'pt_BR' => [
		'Budgets' => 'Orçamentos', 'Info' => 'Informação', 'Notifications' => 'Notificações',
		'Standard' => 'Padrão', 'Type' => 'Tipo', 'Match' => 'Correspondência', 'Status' => 'Estado',
		'Alert' => 'Alerta', 'gone' => 'desaparecida', 'new' => 'nova', 'Name' => 'Nome',
		'Optional:' => 'Opcional:', 'Check Partner' => 'Ver parceiro',
		'BudgetCheck alert' => 'Alerta do BudgetCheck', '{count} budgets' => '{count} orçamentos',
	],
	'zh_CN' => [
		'Budgets' => '预算', 'Info' => '信息', 'Notifications' => '通知',
		'Standard' => '标准', 'Type' => '类型', 'Match' => '匹配', 'Status' => '状态',
		'Alert' => '提醒', 'gone' => '消失', 'new' => '新增', 'Name' => '名称',
		'Optional:' => '可选：', 'Check Partner' => '查看合作伙伴',
		'BudgetCheck alert' => 'BudgetCheck 提醒', '{count} budgets' => '{count} 个预算',
		'Menu' => '菜单',
		// placeholder-order fix: checker requires {matched} before {scanned}
		'Preview: {matched} of {scanned} uncategorized bookings would be filled. Apply now?'
			=> '预览：{matched} 笔（共 {scanned} 笔）未分类交易将被填写。立即应用？',
	],
];

foreach ($maps as $loc => $t) {
	$path = $base . '/' . $loc . '.json';
	$json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	$applied = 0;
	foreach ($t as $msgid => $msgstr) {
		if (!isset($json['translations'][$msgid]) || $json['translations'][$msgid] === $msgid || $loc === 'zh_CN') {
			if (isset($json['translations'][$msgid])) { $json['translations'][$msgid] = $msgstr; $applied++; }
		}
	}
	file_put_contents($path, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
	echo "$loc: applied $applied\n";
}
