<?php

namespace App\Http\Controllers\Api\V1\Records;

use App\Enums\RecordCategory;
use App\Enums\RecordVisibility;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Records\SetVisibilityRequest;
use App\Http\Requests\Api\V1\Records\StoreRecordRequest;
use App\Services\Records\RecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The patient's own records. */
class RecordController extends ApiController
{
    public function __construct(private readonly RecordService $records) {}

    public function index(Request $request): JsonResponse
    {
        $category = RecordCategory::tryFrom((string) $request->query('category'));

        $list = $this->records->listFor($request->user('web')->patient, $category)
            ->map(fn ($r) => RecordService::present($r))->all();

        return $this->ok(['records' => $list]);
    }

    public function store(StoreRecordRequest $request): JsonResponse
    {
        $record = $this->records->createByPatient($request->user('web')->patient, $request->validated());

        return $this->created(['record' => RecordService::present($record)], 'Record saved.');
    }

    public function visibility(SetVisibilityRequest $request, string $id): JsonResponse
    {
        $record = $this->records->setVisibility(
            $request->user('web')->patient, $id, RecordVisibility::from($request->validated('visibility')),
        );

        return $this->ok(['record' => RecordService::present($record)], 'Visibility updated.');
    }
}
