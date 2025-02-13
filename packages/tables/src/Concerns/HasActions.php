<?php

namespace Filament\Tables\Concerns;

use Closure;
use Filament\Forms\ComponentContainer;
use Filament\Support\Actions\Exceptions\Hold;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Illuminate\Database\Eloquent\Model;

/**
 * @property ComponentContainer $mountedTableActionForm
 */
trait HasActions
{
    public $mountedTableAction = null;

    public $mountedTableActionData = [];

    public $mountedTableActionRecord = null;

    protected array $cachedTableActions;

    protected ?Model $cachedMountedTableActionRecord = null;
    protected $cachedMountedTableActionRecordKey = null;

    public function cacheTableActions(): void
    {
        $actions = Action::configureUsing(
            Closure::fromCallable([$this, 'configureTableAction']),
            fn (): array => $this->getTableActions(),
        );

        $this->cachedTableActions = collect($actions)
            ->mapWithKeys(function (Action | ActionGroup $action, int $index): array {
                if ($action instanceof ActionGroup) {
                    foreach ($action->getActions() as $groupedAction) {
                        $groupedAction->table($this->getCachedTable());
                    }

                    return [$index => $action];
                }

                $action->table($this->getCachedTable());

                return [$action->getName() => $action];
            })
            ->toArray();
    }

    protected function configureTableAction(Action $action): void
    {
    }

    public function callMountedTableAction(?string $arguments = null)
    {
        $action = $this->getMountedTableAction();

        if (! $action) {
            return;
        }

        if ($action->isDisabled()) {
            return;
        }

        $form = $this->getMountedTableActionForm();

        if ($action->hasForm()) {
            $action->callBeforeFormValidated();

            $action->formData($form->getState());

            $action->callAfterFormValidated();
        }

        $action->callBefore();

        try {
            $result = $action->call([
                'arguments' => $arguments ? json_decode($arguments, associative: true) : [],
                'form' => $form,
            ]);
        } catch (Hold $exception) {
            return;
        }

        try {
            return $action->callAfter() ?? $result;
        } finally {
            $this->mountedTableAction = null;
            $this->mountedTableActionRecord(null);
            $action->resetFormData();

            $this->dispatchBrowserEvent('close-modal', [
                'id' => static::class . '-table-action',
            ]);
        }
    }

    public function mountedTableActionRecord($record): void
    {
        $this->mountedTableActionRecord = $record;
    }

    public function mountTableAction(string $name, ?string $record = null)
    {
        $this->mountedTableAction = $name;
        $this->mountedTableActionRecord($record);

        $action = $this->getMountedTableAction();

        if (! $action) {
            return;
        }

        if ($action->isDisabled()) {
            return;
        }

        $this->cacheForm(
            'mountedTableActionForm',
            fn () => $this->getMountedTableActionForm(),
        );

        if ($action->hasForm()) {
            $action->callBeforeFormFilled();
        }

        app()->call($action->getMountUsing(), [
            'action' => $action,
            'form' => $this->getMountedTableActionForm(),
            'record' => $this->getMountedTableActionRecord(),
        ]);

        if ($action->hasForm()) {
            $action->callAfterFormFilled();
        }

        if (! $action->shouldOpenModal()) {
            return $this->callMountedTableAction();
        }

        $this->resetErrorBag();

        $this->dispatchBrowserEvent('open-modal', [
            'id' => static::class . '-table-action',
        ]);
    }

    public function getCachedTableActions(): array
    {
        return $this->cachedTableActions;
    }

    public function getMountedTableAction(): ?Action
    {
        if (! $this->mountedTableAction) {
            return null;
        }

        return $this->getCachedTableAction($this->mountedTableAction) ?? $this->getCachedTableEmptyStateAction($this->mountedTableAction) ?? $this->getCachedTableHeaderAction($this->mountedTableAction);
    }

    public function getMountedTableActionForm(): ?ComponentContainer
    {
        $action = $this->getMountedTableAction();

        if (! $action) {
            return null;
        }

        if ((! $this->isCachingForms) && $this->hasCachedForm('mountedTableActionForm')) {
            return $this->getCachedForm('mountedTableActionForm');
        }

        return $this->makeForm()
            ->schema($action->getFormSchema())
            ->model($this->getMountedTableActionRecord() ?? $this->getTableQuery()->getModel()::class)
            ->statePath('mountedTableActionData');
    }

    public function getMountedTableActionRecord(): ?Model
    {
        $recordKey = $this->mountedTableActionRecord;

        if ($this->cachedMountedTableActionRecord && ($this->cachedMountedTableActionRecordKey === $recordKey)) {
            return $this->cachedMountedTableActionRecord;
        }

        $this->cachedMountedTableActionRecordKey = $recordKey;

        return $this->cachedMountedTableActionRecord = $this->resolveTableRecord($recordKey);
    }

    protected function getCachedTableAction(string $name): ?Action
    {
        return $this->findTableAction($name)?->record($this->getMountedTableActionRecord());
    }

    protected function findTableAction(string $name): ?Action
    {
        $actions = $this->getCachedTableActions();

        $action = $actions[$name] ?? null;

        if ($action) {
            return $action;
        }

        foreach ($actions as $action) {
            if (! $action instanceof ActionGroup) {
                continue;
            }

            $groupedAction = $action->getActions()[$name] ?? null;

            if (! $groupedAction) {
                continue;
            }

            return $groupedAction;
        }

        return null;
    }

    protected function getTableActions(): array
    {
        return [];
    }
}
