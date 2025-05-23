<?php

namespace Ahmedkandel\NovaS3MultipartUpload\Http\Controllers;

use Ahmedkandel\NovaS3MultipartUpload\NovaS3MultipartUpload;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\ResourceToolElement;

class FilesController
{
    /**
     * Model instance.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    private $model;

    /**
     * Resource tool instance.
     *
     * @var \Ahmedkandel\NovaS3MultipartUpload\NovaS3MultipartUpload
     */
    private $tool;

    /**
     * Retrieve model and resource tool.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return void
     */
    private function init($request)
    {
        $resource = $request->findResourceOrFail();

        $this->model = $resource->model();

        $fields = $resource->availableFields($request);

        // 1. Find the ResourceToolElement wrapper first
        $toolElement = $fields->first(function ($field) use ($request) {
            if ($field instanceof \Laravel\Nova\ResourceToolElement) {
                $actualTool = $field->panel ?? null;
                return $actualTool instanceof NovaS3MultipartUpload &&
                    $actualTool->attribute == $request->route('field');
            }
            return false; // Only interested in ResourceToolElement wrappers
        });

        // 2. Assign the actual tool from the panel property if the wrapper was found
        $this->tool = $toolElement ? $toolElement->panel : null;

        // 3. Fallback check if the tool wasn't wrapped
        if (!$this->tool) {
            $this->tool = $fields->first(function ($field) use ($request) {
                return $field instanceof NovaS3MultipartUpload &&
                    $field->attribute == $request->route('field');
            });
        }

        abort_unless($this->tool, 404);
    }

    /**
     * Authorize user action.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @param  string  $action
     * @return void
     */
    private function authorize($request, $action)
    {
        abort_unless($this->tool->authorizedToSee($request), 403);

        abort_unless($this->tool->{'can' . $action}, 403);
    }

    /**
     * Return all files information.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function index(NovaRequest $request)
    {
        $this->init($request);

        $this->authorize($request, 'View');

        return [
            'files' => $this->files(),
        ];
    }

    /**
     * Retrieve files information.
     *
     * @return \Illuminate\Support\Collection
     */
    private function files()
    {
        $source = $this->tool->isNestedAttribute() ? $this->model->{$this->tool->attribute} : $this->model;

        return collect($this->tool->hasSingleFile() ? [$source] : $source)
            ->map(function ($file) {
                $data = [];

                foreach ($this->tool->fileInfoColumns() as $key => $column) {
                    $data[$key] = $file[$column] ?? null;
                }

                foreach ($this->tool->fileMetaColumns() as $key => $column) {
                    $data['fileMeta'][$key] = $file[$column] ?? null;
                }

                return $data;
            })
            ->reject(function ($file) {
                return empty($file['fileKey']);
            });
    }

    /**
     * Save file information.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function store(NovaRequest $request)
    {
        $this->init($request);

        $this->authorize($request, 'Upload');

        if ($this->tool->hasSingleFile() && $this->model->{$this->tool->attribute}) {
            $this->deletePreviousFile();
        }

        if ($this->tool->relationship === 'HasOne') {
            $this->model->{$this->tool->attribute}()->delete();
        }

        $data = $this->prepareDataForInsertion($request);

        if ($this->tool->relationship) {
            $this->model->{$this->tool->attribute}()->create($data);
        } else {
            $this->model->update($data);
        }

        return [
            'message' => __('Added file :filename', ['filename' => $request->input('fileName')]),
        ];
    }

    /**
     * Delete previous file before saving the new file information.
     *
     * @return void
     */
    private function deletePreviousFile()
    {
        $path = $this->tool->isNestedAttribute() ? $this->model->{$this->tool->attribute}[$this->tool->fileKeyColumn]
            : $this->model->{$this->tool->fileKeyColumn};

        Storage::disk($this->tool->disk)->delete($path);
    }

    /**
     * Prepare new file information for insertion.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    private function prepareDataForInsertion($request)
    {
        $data = [];

        foreach (array_merge($this->tool->fileInfoColumns(), $this->tool->fileMetaColumns()) as $key => $column) {
            $data[$column] = $request->input($key) ?? $request->input('fileMeta')[$column] ?? null;
        }

        if ($this->tool->isArray) {
            return [$this->tool->attribute => $data];
        }

        if ($this->tool->isMultipleArray) {
            return [$this->tool->attribute => array_merge($this->model->{$this->tool->attribute} ?? [], [$data])];
        }

        return $data;
    }

    /**
     * Download file.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function download(NovaRequest $request)
    {
        $this->init($request);

        $this->authorize($request, 'Download');

        $file = $this->files()->firstWhere('fileKey', $request->route('fileKey'));

        abort_unless($file && Storage::disk($this->tool->disk)->exists($file['fileKey']), 404);

        return [
            'temporaryUrl' => Storage::disk($this->tool->disk)->temporaryUrl(
                $file['fileKey'],
                now()->addDay(),
                ['ResponseContentDisposition' => $request->query('contentDisposition', 'attachment') . '; filename="' . ($file['fileName'] ?? basename($file['fileKey'])) . '"'],
            ),
        ];
    }

    /**
     * Delete file and its related information.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function destroy(NovaRequest $request)
    {
        $this->init($request);

        $this->authorize($request, 'Delete');

        $file = $this->files()->firstWhere('fileKey', $request->route('fileKey'));

        abort_unless($file, 404);

        Storage::disk($this->tool->disk)->delete($file['fileKey']);

        if ($this->tool->relationship) {
            $this->model->{$this->tool->attribute}()->firstWhere($this->tool->fileKeyColumn, $file['fileKey'])->delete();
        } else {
            $this->model->update($this->prepareDataForRemoval($file['fileKey']));
        }

        return [
            'message' => __('Deleted file :filename', ['filename' => ($file['fileName'] ?? $file['fileKey'])]),
        ];
    }

    /**
     * Prepare file information from removal.
     *
     * @param  string  $fileKey
     * @return array
     */
    private function prepareDataForRemoval($fileKey)
    {
        if ($this->tool->isArray) {

            return [$this->tool->attribute => null];
        } elseif ($this->tool->isMultipleArray) {

            return [
                $this->tool->attribute => collect($this->model->{$this->tool->attribute})->reject(function ($file) use ($fileKey) {
                    return $file[$this->tool->fileKeyColumn] === $fileKey;
                })->values(),
            ];
        } else {

            return collect($this->tool->fileInfoColumns())->concat($this->tool->fileMetaColumns())->mapWithKeys(function ($column) {
                return [$column => null];
            })->all();
        }
    }
}
