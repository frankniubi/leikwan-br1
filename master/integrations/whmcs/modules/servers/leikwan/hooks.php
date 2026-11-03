<?php
/**
 * Order-complete redirect for the LeiKwan module.
 *
 * When the order's LeiKwan service is already Active (auto-setup on payment
 * succeeded, e.g. paid from credit or a synchronous gateway), the checkout
 * complete page sends the customer through ServiceSingleSignOn straight into
 * the panel. Otherwise it explains that setup follows payment.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

add_hook('ShoppingCartCheckoutCompletePage', 1, function (array $vars) {
    $orderId = (int) ($vars['orderid'] ?? 0);
    if ($orderId <= 0) {
        return '';
    }
    $services = Capsule::table('tblhosting')
        ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
        ->where('tblhosting.orderid', $orderId)
        ->where('tblproducts.servertype', 'leikwan')
        ->get(['tblhosting.id', 'tblhosting.domainstatus', 'tblproducts.configoption4']);
    if (count($services) === 0) {
        return '';
    }
    $zh = stripos((string) ($_SESSION['Language'] ?? ''), 'chinese') === 0;
    $text = [
        'open_panel' => $zh ? '进入控制面板' : 'Open control panel',
        'ready' => $zh ? '您的服务已开通。' : 'Your service is active.',
        'pending' => $zh ? '付款确认后服务将自动开通，届时可在“我的产品”中一键进入控制面板。' : 'Your service is set up automatically once payment is confirmed. Then open it from My Services.',
    ];

    $active = [];
    foreach ($services as $service) {
        if ($service->domainstatus === 'Active') {
            $active[] = $service;
        }
    }
    if ($active === []) {
        return '<div class="alert alert-info text-center">' . htmlspecialchars($text['pending'], ENT_QUOTES, 'UTF-8') . '</div>';
    }

    $first = $active[0];
    $url = 'clientarea.php?action=productdetails&id=' . (int) $first->id . '&dosinglesignon=1';
    $html = '<div class="alert alert-success text-center">'
        . '<p>' . htmlspecialchars($text['ready'], ENT_QUOTES, 'UTF-8') . '</p>'
        . '<a class="btn btn-success" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($text['open_panel'], ENT_QUOTES, 'UTF-8') . '</a></div>';
    if (count($active) === 1 && $first->configoption4 === 'on') {
        $html .= '<script>setTimeout(function(){window.location.href=' . json_encode($url) . ';},1500);</script>';
    }

    return $html;
});
