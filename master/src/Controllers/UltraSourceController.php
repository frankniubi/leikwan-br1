<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\UltraInventoryService;
use Leikwan\Master\Support\UltraMaterialService;

final class UltraSourceController extends BaseController
{
    public function __construct(
        \Leikwan\Master\Config $config,
        \Leikwan\Master\Database\Database $db,
        private UltraMaterialService $materials,
        private UltraInventoryService $inventory,
    ) {
        parent::__construct($config, $db);
    }

    public function jobs(Request $request, array $params): Response
    {
        $sourceId = $this->materials->verifySourceRequest($request);
        $lineIds = $this->materials->dueLineIds(600);
        $this->inventory->markSourceContact($sourceId, 'jobs', ['requested' => count($lineIds)]);
        return $this->json([
            'ok' => true,
            'source_id' => $sourceId,
            'line_ids' => $lineIds,
            'upload_ticket' => $lineIds !== [] ? $this->materials->issueUploadTicket($sourceId, $lineIds) : '',
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    public function upload(Request $request, array $params): Response
    {
        $sourceId = $this->materials->verifySourceRequest($request);
        $ticket = trim((string) $request->input('upload_ticket', ''));
        $materials = $request->input('materials', []);
        if (!is_array($materials)) {
            $materials = [];
        }
        $accepted = $this->materials->acceptSourceUpload($sourceId, $ticket, $materials);
        $this->inventory->markSourceContact($sourceId, 'materials', ['accepted' => count($accepted)]);
        return $this->json([
            'ok' => true,
            'accepted_line_ids' => $accepted,
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    public function inventory(Request $request, array $params): Response
    {
        $sourceId = $this->materials->verifySourceRequest($request);
        if (strlen($request->rawBody()) > UltraInventoryService::MAX_INVENTORY_BYTES) {
            throw new ApiException('ultra_inventory_too_large', 413);
        }
        $accepted = $this->inventory->acceptInventory($sourceId, $request->body());
        return $this->json([
            'ok' => true,
            'accepted' => $accepted,
            'inventory_fingerprint' => strtolower(trim((string) $request->input('inventory_fingerprint', ''))),
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}
