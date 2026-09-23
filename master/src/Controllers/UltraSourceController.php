<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\UltraCandidateReviewService;
use Leikwan\Master\Support\UltraInventoryService;
use Leikwan\Master\Support\UltraMaterialService;

final class UltraSourceController extends BaseController
{
    public function __construct(
        \Leikwan\Master\Config $config,
        \Leikwan\Master\Database\Database $db,
        private UltraMaterialService $materials,
        private UltraInventoryService $inventory,
        private UltraCandidateReviewService $reviews,
    ) {
        parent::__construct($config, $db);
    }

    public function jobs(Request $request, array $params): Response
    {
        $sourceId = $this->materials->verifySourceRequest($request);
        $lineIds = $this->materials->dueLineIds($sourceId, $this->materials->materialRefreshWindowSeconds());
        // A quarantined line can have been condemned by a client whose own
        // network is broken. The source host is the only vantage point that can
        // disprove that, so it is handed the review alongside its material work.
        // An agent tells us how many reviews it will actually run this cycle.
        // Ones that do not ask for any must not be handed one: it would sit in
        // "running" until it went stale and burn an attempt for nothing.
        $reviews = $this->reviews->requestForSource(
            $sourceId,
            max(0, min(8, (int) $request->input('reviews_capacity', 0)))
        );
        $this->inventory->markSourceContact($sourceId, 'jobs', [
            'requested' => count($lineIds),
            'reviews' => count($reviews),
        ]);
        return $this->json([
            'ok' => true,
            'source_id' => $sourceId,
            'line_ids' => $lineIds,
            'reviews' => $reviews,
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

    /**
     * Result of a line review run by the source host.
     *
     * Expected body:
     *   review_id  int
     *   passed     bool   - big-packet UDP AND DNS both usable for the whole window
     *   detail     object - optional evidence (probe counts, loss, last error)
     *   note       string - optional human readable summary
     */
    public function review(Request $request, array $params): Response
    {
        $sourceId = $this->materials->verifySourceRequest($request);
        $reviewId = (int) $request->input('review_id', 0);
        if ($reviewId <= 0) {
            throw new ApiException('ultra_review_id_required', 422);
        }
        $passed = filter_var($request->input('passed', false), FILTER_VALIDATE_BOOLEAN);
        $detail = $request->input('detail', []);
        if (!is_array($detail)) {
            $detail = [];
        }
        $note = (string) $request->input('note', '');
        $updated = $this->reviews->recordResult($reviewId, $passed, $detail, $note);
        if ($updated === []) {
            throw new ApiException('ultra_review_unknown', 404);
        }
        $this->inventory->markSourceContact($sourceId, 'review', [
            'review_id' => $reviewId,
            'passed' => $passed,
        ]);
        return $this->json([
            'ok' => true,
            'review_id' => $reviewId,
            'state' => (string) ($updated['state'] ?? ''),
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
