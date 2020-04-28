<?php

namespace atk4\mastercrud;

use atk4\data\Model;
use atk4\ui\BreadCrumb;
use atk4\ui\Card;
use atk4\ui\CardTable;
use atk4\ui\CRUD;
use atk4\ui\Exception;
use atk4\ui\jsModal;
use atk4\ui\Tabs;
use atk4\ui\View;

class MasterCRUD extends View
{
    /** @var BreadCrumb object */
    public $crumb = null;

    /** @var array Default BreadCrumb seed */
    public $defaultCrumb = [BreadCrumb::class, 'Unspecified', 'big'];

    /** @var Model the top-most model */
    public $rootModel;

    /** @var string Tab Label for detail */
    public $detailLabel = 'Details';

    /** @var array of properties which are reserved for MasterCRUD and can't be used as model names */
    protected $reserved_properties = ['_crud', '_tabs', '_card', 'caption', 'columnActions', 'menuActions'];

    /** @var string Delimiter to generate url path DO NOT USED '?', '#' or '/' */
    protected $pathDelimiter = '-';

    /** @var View Tabs view */
    protected $tabs;

    /** @var array */
    protected $path;

    /** @var array Default Crud for all model. You may override this value per model using $def['_crud'] in setModel */
    public $defaultCrud = [CRUD::class, 'ipp' => 25];

    /** @var array Default Tabs for all model. You may override this value per model using $def['_tabs'] in setModel */
    public $defaultTabs = [Tabs::class];

    /** @var array Default Card for all model. You may override this value per model using $def['_card'] in setModel */
    public $defaultCard = [CardTable::class];

    /**
     * Initialization.
     * @throws \atk4\core\Exception
     */
    public function init()
    {
        if (in_array($this->pathDelimiter, ['?', '#', '/'])) {
            throw new Exception('Can\'t use URL reserved charater (?,#,/) as path delimiter');
        }

        // add BreadCrumb view
        if (!$this->crumb) {
            $this->crumb = $this->add($this->defaultCrumb);
        }
        $this->add([View::class, 'ui'=>'divider']);

        parent::init();
    }

    /**
     * Sets model.
     *
     * Use $defs['_crud'] to set seed properties for CRUD view.
     * Use $defs['_tabs'] to set seed properties for Tabs view.
     * Use $defs['_card'] to set seed properties for Card view.
     *
     * For example setting different seeds for Client and Invoice model passing seeds value in array 0.
     * $mc->setModel(new Client($app->db),
     *   [
     *       ['_crud' => ['CRUD', 'ipp' => 50]],
     *       'Invoices'=>[
     *           [
     *               '_crud' =>['CRUD', 'ipp' => 25, 'displayFields' => ['reference', 'total']],
     *               '_card' =>['Card', 'useLabel' => true]
     *           ],
     *           'Lines'=>[],
     *           'Allocations'=>[]
     *       ],
     *       'Payments'=>[
     *           'Allocations'=>[]
     *       ]
     *   ]
     * );
     *
     *
     * @param Model      $m
     * @param array|null $defs
     *
     * @return Model
     * @throws \atk4\core\Exception
     * @throws \atk4\data\Exception
     * @throws \atk4\ui\Exception
     */
    public function setModel(Model $m, array $defs = null)
    {
        $this->rootModel = $m;

        $this->crumb->addCrumb($this->getCaption($m), $this->url());

        // extract path
        $this->path = explode($this->pathDelimiter, $this->app->stickyGet('path'));
        if ($this->path[0] == '') {
            unset($this->path[0]);
        }

        $defs = $this->traverseModel($this->path, $defs ?? []);

        $arg_name = $this->model->table . '_id';
        $arg_val = $this->app->stickyGet($arg_name);
        if ($arg_val && $this->model->tryLoad($arg_val)->loaded()) {
            // initialize Tabs
            $this->initTabs($defs);
        } else {
            // initialize CRUD
            $this->initCrud($defs);
        }

        $this->crumb->popTitle();

        return $this->rootModel;
    }

    /**
     * Return model caption.
     *
     * @param Model $m
     *
     * @return string
     */
    public function getCaption(Model $m): string
    {
        return $m->getModelCaption();
    }

    /**
     * Return title field value.
     *
     * @param Model $m
     *
     * @return string
     */
    public function getTitle(Model $m): string
    {
        return $m->getTitle();
    }

    /**
     * Initialize tabs.
     *
     * @param array $defs
     * @param View $view Parent view
     *
     * @throws \atk4\core\Exception
     */
    public function initTabs(array $defs, View $view = null)
    {
        if ($view === null) {
            $view = $this;
        }

        $this->tabs = $view->add($this->getTabsSeed($defs));
        $this->app->stickyGet($this->model->table . '_id');

        $this->crumb->addCrumb($this->getTitle($this->model), $this->tabs->url());

        // Use callback to refresh detail tabs when related model is changed.
        $this->tabs->addTab($this->detailLabel, function ($p) use ($defs) {
            $card = $p->add($this->getCardSeed($defs));
            $card->setModel($this->model);
        });

        if (!$defs) {
            return;
        }

        foreach ($defs as $ref => $subdef) {
            if (is_numeric($ref) || in_array($ref, $this->reserved_properties)) {
                continue;
            }
            $m = $this->model->ref($ref);

            $caption = $this->model->getRef($ref)->caption ?? $this->getCaption($m);

            $this->tabs->addTab($caption, function ($p) use ($subdef, $m, $ref) {
                $sub_crud = $p->add($this->getCRUDSeed($subdef));

                $sub_crud->setModel(clone $m);
                $t = $p->urlTrigger ?: $p->name;

                if (isset($sub_crud->table->columns[$m->title_field])) {
                    $sub_crud->addDecorator($m->title_field, ['Link', [$t => false, 'path' => $this->getPath($ref)], [$m->table . '_id'=>'id']]);
                }

                $this->addActions($sub_crud, $subdef);
            });
        }
    }

    /**
     * Initialize CRUD.
     *
     * @param array $defs
     * @param View $view Parent view
     *
     * @throws \atk4\core\Exception
     */
    public function initCrud(array $defs, View $view = null)
    {
        if ($view === null) {
            $view = $this;
        }

        $crud = $view->add($this->getCRUDSeed($defs));
        $crud->setModel($this->model);

        if (isset($crud->table->columns[$this->model->title_field])) {
            $crud->addDecorator($this->model->title_field, ['Link', [], [$this->model->table . '_id'=>'id']]);
        }

        $this->addActions($crud, $defs);
    }

    /**
     * Provided with a relative path, add it to the current one
     * and return string.
     *
     * @param string|array $rel
     *
     * @return false|string
     */
    public function getPath($rel)
    {
        $path = $this->path;

        if (!is_array($rel)) {
            $rel = explode($this->pathDelimiter, $rel);
        }

        foreach ($rel as $rel_one) {
            if ($rel_one == '..') {
                array_pop($path);
                continue;
            }

            if ($rel_one == '') {
                $path = [];
                continue;
            }

            $path[] = $rel_one;
        }

        $res = join($this->pathDelimiter, $path);

        return $res == '' ? false : $res;
    }

    /**
     * Adds CRUD action buttons.
     *
     * @param View $crud
     * @param array $defs
     *
     * @throws \atk4\core\Exception
     */
    public function addActions($crud, $defs)
    {
        if ($ma = $defs['menuActions'] ?? null) {
            is_array($ma) || $ma = [$ma];

            foreach ($ma as $key => $action) {
                if (is_numeric($key)) {
                    $key = $action;
                }

                if (is_string($action)) {
                    $crud->menu->addItem($key)->on(
                        'click',
                        new jsModal('Executing ' . $key, $this->add('VirtualPage')->set(function ($p) use ($key, $action, $crud) {

                            // TODO: this does ont work within a tab :(
                            $p->add(new MethodExecutor($crud->model, $key));
                        }))
                    );
                }

                if ($action instanceof \Closure) {
                    $crud->menu->addItem($key)->on(
                        'click',
                        new jsModal('Executing ' . $key, $this->add('VirtualPage')->set(function ($p) use ($key, $action) {
                            $action($p, $this->model, $key);
                        }))
                    );
                }
            }
        }

        if ($ca = $defs['columnActions'] ?? null) {
            is_array($ca) || $ca = [$ca];

            foreach ($ca as $key => $action) {
                if (is_numeric($key)) {
                    $key = $action;
                }

                if (is_string($action)) {
                    $label = ['icon'=>$action];
                }

                is_array($action) || $action = [$action];

                if (isset($action['icon'])) {
                    $label = ['icon'=>$action['icon']];
                    unset($action['icon']);
                }

                if (isset($action[0]) && $action[0] instanceof \Closure) {
                    $crud->addModalAction($label ?: $key, $key, function ($p, $id) use ($action, $key, $crud) {
                        call_user_func($action[0], $p, $crud->model->load($id));
                    });
                } else {
                    $crud->addModalAction($label ?: $key, $key, function ($p, $id) use ($action, $key, $crud) {
                        $p->add(new MethodExecutor($crud->model->load($id), $key, $action));
                    });
                }
            }
        }
    }

    /**
     * Return seed for CRUD.
     *
     * @param array $defs
     *
     * @return array|View
     * @throws \atk4\core\Exception
     */
    protected function getCRUDSeed(array $defs)
    {
        return $defs[0]['_crud'] ?? $this->defaultCrud;
    }

    /**
     * Return seed for Tabs.
     *
     * @param array $defs
     *
     * @return array|View
     * @throws \atk4\core\Exception
     */
    protected function getTabsSeed(array $defs)
    {
        return $defs[0]['_tabs'] ?? $this->defaultTabs;
    }

    /**
     * Return seed for Card.
     *
     * @param array $defs
     *
     * @return array|View
     * @throws \atk4\core\Exception
     */
    protected function getCardSeed(array $defs)
    {
        return $defs[0]['_card'] ?? $this->defaultCard;
    }

    /**
     * Given a path and arguments, find and load the right model.
     *
     * @param array $path
     * @param array $defs
     *
     * @return array
     * @throws \atk4\core\Exception
     * @throws \atk4\data\Exception
     * @throws \atk4\ui\Exception
     */
    public function traverseModel(array $path, array $defs): array
    {
        $m = $this->rootModel;

        $path_part = [''];

        foreach ($path as $p) {
            if (!$p) {
                continue;
            }

            if (!isset($defs[$p])) {
                throw new Exception(['Path is not defined', 'path'=>$path, 'defs'=>$defs]);
            }

            $defs = $defs[$p];

            // argument of a current model should be passed if we are traversing
            $arg_name = $m->table . '_id';
            $arg_val = $this->app->stickyGet($arg_name);

            if ($arg_val === null) {
                throw new Exception(['Argument value is not specified', 'arg'=>$arg_name]);
            }

            // load record and traverse
            $m->load($arg_val);

            $this->crumb->addCrumb(
                $this->getTitle($m),
                $this->url(['path'=>$this->getPath($path_part)])
            );

            $m = $m->ref($p);
            $path_part[]=$p;
        }

        parent::setModel($m);

        return $defs;
    }
}
