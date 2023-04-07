<?php namespace Vsch\TranslationManager;

use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\ParameterBag;
use Vsch\TranslationManager\Classes\TranslationLocales;
use Vsch\TranslationManager\Events\TranslationsPublished;
use Vsch\TranslationManager\Models\Translation;
use Vsch\TranslationManager\Models\UserLocales;

include_once(__DIR__ . '/Support/finediff.php');

class Controller extends BaseController
{
    const COOKIE_LANG_LOCALE = 'lang';
    const COOKIE_TRANS_LOCALE = 'trans';
    const COOKIE_PRIM_LOCALE = 'prim';
    const COOKIE_DISP_LOCALES = 'disp';
    const COOKIE_SHOW_USAGE = 'show-usage';
    const COOKIE_CONNECTION_NAME = 'connection-name';
    const COOKIE_TRANS_FILTERS = 'trans-filters';
    const COOKIE_SHOW_UNPUBLISHED = 'show-unpublished';

    /** @var \Vsch\TranslationManager\Manager */
    protected $manager;
    protected $packagePrefix;
    protected $package;
    protected $sqltraces;
    protected $logSql;
    protected $connectionList;
    private $cookiePrefix;
    private $showUsageInfo;
    private $webUIState;
    private $translatorRepository;

    /* @var $transLocales TranslationLocales */
    private $transLocales; // all translation locale information for the session
    private $transFilters;

    private $localeData;

    public function __construct()
    {

        $this->package = \Vsch\TranslationManager\ManagerServiceProvider::PACKAGE;
        $this->packagePrefix = $this->package . '::';
        $this->manager = App::make($this->package);
        $this->translatorRepository = $this->manager->getRepository();

        $this->connectionList = [];
        $this->connectionList[''] = 'default';
        $connections = $this->manager->config(Manager::DB_CONNECTIONS_KEY);
        if ($connections && array_key_exists(App::environment(), $connections)) {
            foreach ($connections[App::environment()] as $key => $value) {
                if (array_key_exists('description', $value)) {
                    $this->connectionList[$key] = $value['description'];
                } else {
                    $this->connectionList[$key] = $key;
                }
            }
        }

        $this->cookiePrefix = $this->manager->config('persistent_prefix', 'K9N6YPi9WHwKp6E3jGbx');

        // cookies are not available yet (they are but appear to be encrypted). They will be by the time middleware is called 
        $this->middleware(function ($request, $next) {
            $this->manager->setWebUI(); // no need to clear
            $this->initialize();
            return $next($request);
        });
    }

    private function initialize()
    {
        $connectionName = Cookie::has($this->cookieName(self::COOKIE_CONNECTION_NAME)) ? Cookie::get($this->cookieName(self::COOKIE_CONNECTION_NAME)) : '';
        $this->changeConnectionName($connectionName);
    }

    public function getConnectionName()
    {
        return $this->manager->getConnectionName();
    }

    /**
     * @param $t
     * @return bool
     */
    public function isJsonKeyLocale($t): bool
    {
        return $t->group === Manager::JSON_GROUP && $t->locale === 'json';
    }

    protected function getConnection()
    {
        $connection = $this->manager->getConnection();
        return $connection;
    }

    /**
     * @return string
     */
    private function normalizedConnectionName(): string
    {
        return ($this->manager->getNormalizedConnectionName($this->getConnectionName()));
    }

    /**
     * Change default connection used for this login/session and reload
     * persistent data if it the connection is different from what was before
     *
     * @param $connection string    connection name to change to
     */
    public function changeConnectionName($connection)
    {
        if (!array_key_exists($connection, $this->connectionList)) $connection = '';

        $resolvedConnection = $this->manager->getResolvedConnectionName($connection);
        if ($resolvedConnection != $this->getConnectionName()) {
            // changed
            // save the new connection to be used as default for the user
            try {
                $this->manager->setConnectionName($resolvedConnection);
                $this->loadPersistentData();
                Cookie::queue($this->cookieName(self::COOKIE_CONNECTION_NAME), $connection, 60 * 24 * 365 * 1);
            } catch (\Exception $e) {
                Cookie::queue($this->cookieName(self::COOKIE_CONNECTION_NAME), '', 60 * 24 * 365 * 1);

                if (!$this->manager->isDefaultTranslationConnection($resolvedConnection)) {
                    // reset to default connection
                    $this->manager->setConnectionName('');
                    $this->loadPersistentData();
                } else {
                    // invalid database config or no connection
                }
            }
        } else {
            Cookie::queue($this->cookieName(self::COOKIE_CONNECTION_NAME), $connection, 60 * 24 * 365 * 1);
            if (!$this->transLocales) {
                $this->loadPersistentData();
            }
        }
    }

    private function loadPersistentData()
    {
        $this->initTranslationLocales();
        $this->normalizeTranslationLocales();
        App::setLocale($this->transLocales->currentLocale);

        // save them in case they changed
        $transLocales = $this->transLocales;

        $transFilters = Cookie::get($this->cookieName(self::COOKIE_TRANS_FILTERS));
        if (is_array($transFilters)) {
            $this->transFilters = $transFilters;
        } else if (is_string($transFilters)) {
            $this->transFilters = json_decode($transFilters, true);
        } else {
            $this->transFilters = ['filter' => 'show-all', 'regex' => ''];
        }

        $this->showUsageInfo = Cookie::get($this->cookieName(self::COOKIE_SHOW_USAGE), false);
    }

    private function initTranslationLocales()
    {
        $appLocale = App::getLocale();
        $currentLocale = Cookie::get($this->cookieName(self::COOKIE_LANG_LOCALE), $appLocale);
        $primaryLocale = Cookie::get($this->cookieName(self::COOKIE_PRIM_LOCALE), $this->manager->config('primary_locale', 'en'));
        $translatingLocale = Cookie::get($this->cookieName(self::COOKIE_TRANS_LOCALE), $currentLocale);
        $displayLocales = Cookie::get($this->cookieName(self::COOKIE_DISP_LOCALES));
        $displayLocales = $displayLocales ? array_unique(explode(',', $displayLocales)) : [];

        $transLocales = new TranslationLocales($this->manager);
        $transLocales->appLocale = $appLocale;
        $transLocales->currentLocale = $currentLocale;
        $transLocales->primaryLocale = $primaryLocale;
        $transLocales->translatingLocale = $translatingLocale;
        $transLocales->displayLocales = $displayLocales;

        // this messes up React UI because TransFilters overwrites it before appSettings get a chance to save
        //        App::setLocale($transLocales->currentLocale);
        $this->transLocales = $transLocales;
    }

    private function normalizeTranslationLocales()
    {
        $transLocales = $this->transLocales;

        $allLocales = ManagerServiceProvider::getLists($this->getTranslation()->groupBy('locale')->pluck('locale')) ?: [];
        $transLocales->allLocales = $allLocales;

        if ((!Gate::allows(Manager::ABILITY_ADMIN_TRANSLATIONS)) && $this->manager->areUserLocalesEnabled()) {
            // see what locales are available for this user
            $userId = Auth::id();
            if ($userId !== null) {
                $userLocalesModel = new UserLocales();
                $userLocalesModel->setConnection($this->getConnectionName());
                $userLocalesResult = $userLocalesModel->query()->where('user_id', $userId)->first();
                if ($userLocalesResult && $userLocalesResult->locales && trim($userLocalesResult->locales)) {
                    $transLocales->userLocales = explode(',', $userLocalesResult->locales);
                }
            }
        }

        $transLocales->normalize();

        // needed to properly resolve JSON default keys when they are based on the primary locale
        $this->manager->setPrimaryLocale($transLocales->primaryLocale);
    }

    public function cookieName($cookie)
    {
        return $this->cookiePrefix . $cookie;
    }

    protected function getTranslation()
    {
        return $this->manager->getTranslation();
    }

    /**
     * @return mixed
     */
    public static function active($url)
    {
        $url = url($url, null, false);
        $url = str_replace('https:', 'http:', $url);
        $req = str_replace('https:', 'http:', Request::url());
        $ret = ($pos = strpos($req, $url)) === 0 && (strlen($req) === strlen($url) || substr($req, strlen($url), 1) === '?' || substr($req, strlen($url), 1) === '#');
        return $ret;
    }

    public function isLocaleEnabled($locale)
    {
        $packLocales = TranslationLocales::packLocales($this->transLocales->userLocales);
        if (!is_array($locale)) $locale = array($locale);
        foreach ($locale as $item) {
            if (!str_contains($packLocales, ',' . $item . ',')) return false;
        }
        return true;
    }

    /*
         * Standard WEB UI - API, not REST
         * 
         */

    public function getSearch()
    {
        $q = Request::get('q');

        $translations = $this->computeSearch($q, $this->transLocales->displayLocales);
        $numTranslations = count($translations);

        return View::make($this->packagePrefix . 'search')
            ->with('controller', ManagerServiceProvider::CONTROLLER_PREFIX . get_class($this))
            ->with('userLocales', TranslationLocales::packLocales($this->transLocales->userLocales))
            ->with('package', $this->package)
            ->with('translations', $translations)
            ->with('numTranslations', $numTranslations);
    }

    public function getView($group = null)
    {
        return $this->getIndex($group);
    }

    public function getIndex($group = null)
    {
        return $this->processIndex($group);
    }

    private function processIndex($group = null)
    {
        $currentLocale = $this->transLocales->currentLocale;
        $primaryLocale = $this->transLocales->primaryLocale;
        $translatingLocale = $this->transLocales->translatingLocale;
        $locales = $this->transLocales->locales;
        $displayLocales = $this->transLocales->displayLocales;
        $userLocales = $this->transLocales->userLocales;

        $groups = array('' => noEditTrans($this->packagePrefix . 'messages.choose-group')) + $this->manager->getGroupList();

        if ($group != null && !array_key_exists($group, $groups)) {
            return Redirect::action(ManagerServiceProvider::CONTROLLER_PREFIX . get_class($this) . '@getIndex');
        }

        $numChanged = $this->getTranslation()->where('group', $group)->where('status', Translation::STATUS_CHANGED)->count();

        // to allow proper handling of nested directory structure we need to copy the keys for the group for all missing
        // translations, otherwise we don't know what the group and key looks like.
        //$allTranslations = $this->getTranslation()->where('group', $group)->orderBy('key', 'asc')->get();
        $allTranslations = $this->translatorRepository->allTranslations($group, $this->transLocales->displayLocales);

        $numTranslations = count($allTranslations);
        $translations = array();
        if ($group !== Manager::JSON_GROUP) {
            foreach ($allTranslations as $t) {
                $translations[$t->key][$t->locale] = $t;
            }
        } else {
            $jsonKeyMap = [];
            foreach ($allTranslations as $t) {
                if (!$this->isJsonKeyLocale($t)) {
                    $translations[$t->key][$t->locale] = $t;
                } else {
                    $jsonKeyMap[] = $t;
                }
            }

            $isFromPrimary = $this->manager->isNewJsonKeyFromPrimaryLocale();
            foreach ($jsonKeyMap as $t) {
                $primaryValue = $isFromPrimary ? $this->getPrimaryValue($translations, $t->key, $primaryLocale) : null;
                $this->adjustJsonLocaleTranslation($t, $primaryValue);
                $translations[$t->key][$t->locale] = $t;
            }
        }

        $this->manager->cacheGroupTranslations($group, $this->transLocales->displayLocales, array_keys($translations));

        $summary = $this->computeSummary($displayLocales);

        $mismatches = null;
        $mismatchEnabled = $this->manager->config('mismatch_enabled');

        if ($mismatchEnabled) {
            $mismatches = $this->computeMismatches($primaryLocale, $translatingLocale);
        }

        $show_usage_enabled = $this->manager->config(Manager::LOG_KEY_USAGE_INFO_KEY, false);

        $userList = $this->computeUserList();

        $adminEnabled = $this->manager->config('admin_enabled') &&
            Gate::allows(Manager::ABILITY_ADMIN_TRANSLATIONS);

        $userLocalesEnabled = $this->manager->areUserLocalesEnabled() && $userList;

        $packedUserLocales = TranslationLocales::packLocales($userLocales);
        $displayLocalesAssoc = array_combine($displayLocales, $displayLocales);

        $disableReactUI = true;
        $disableReactUILink = true;

        $translator = App::make('translator');

        $view = View::make($this->packagePrefix . 'index')
            ->with('disableUiLink', $disableReactUI || $disableReactUILink)
            ->with('controller', ManagerServiceProvider::CONTROLLER_PREFIX . get_class($this))
            ->with('package', $this->package)
            ->with('public_prefix', ManagerServiceProvider::PUBLIC_PREFIX)
            ->with('show_unpublished', $translator->getShowUnpublished())
            ->with('translations', $translations)
            ->with('yandex_key', !!$this->manager->config('yandex_translator_key'))
            ->with('aws_access_key', !!$this->manager->config('aws_secret_access_key'))
            ->with('aws_secret_access_key', !!$this->manager->config('aws_secret_access_key'))
            ->with('aws_endpoint', !!$this->manager->config('aws_endpoint'))
            ->with('aws_region', !!$this->manager->config('aws_region'))
            ->with('azure_access_key', !!$this->manager->config('azure_secret_access_key'))
            ->with('azure_endpoint', !!$this->manager->config('azure_endpoint'))
            ->with('azure_region', !!$this->manager->config('azure_region'))
            ->with('locales', $locales)
            ->with('primaryLocale', $primaryLocale)
            ->with('currentLocale', $currentLocale)
            ->with('translatingLocale', $translatingLocale)
            ->with('displayLocales', $displayLocalesAssoc)
            ->with('userLocales', $packedUserLocales)
            ->with('groups', $groups)
            ->with('group', $group)
            ->with('numTranslations', $numTranslations)
            ->with('numChanged', $numChanged)
            ->with('adminEnabled', $adminEnabled)
            ->with('mismatchEnabled', $mismatchEnabled)
            ->with('userLocalesEnabled', $userLocalesEnabled)
            ->with('stats', $summary)
            ->with('mismatches', $mismatches)
            ->with('show_usage', $this->showUsageInfo && $show_usage_enabled)
            ->with('usage_info_enabled', $show_usage_enabled)
            ->with('connection_list', $this->connectionList)
            ->with('transFilters', $this->transFilters)
            ->with('userList', $userList)
            ->with('markdownKeySuffix', $this->manager->config(Manager::MARKDOWN_KEY_SUFFIX))
            ->with('connection_name', $this->normalizedConnectionName());

        return $view;
    }

    public function getToggleInPlaceEdit()
    {
        inPlaceEditing(!inPlaceEditing());
        if (App::runningUnitTests()) return Redirect::to('/');
        return !is_null(Request::header('referer')) ? Redirect::back() : Redirect::to('/');
    }

    public function getToggleShowUnpublished()
    {
        /* @var $translator Translator */
        $translator = App::make('translator');
        $translator->setShowUnpublished(!$translator->getShowUnpublished());
        if (App::runningUnitTests()) return Redirect::to('/');
        return !is_null(Request::header('referer')) ? Redirect::back() : Redirect::to('/');
    }

    public function getTranslationLocales()
    {
        $connection = Request::get("c");
        $this->changeConnectionName($connection);
        $this->transLocales->currentLocale = Request::get("l");
        $this->transLocales->primaryLocale = Request::get("p");
        $this->transLocales->translatingLocale = Request::get("t");
        $this->transLocales->displayLocales = Request::get("d");
        $this->normalizeTranslationLocales();

        $displayLocales = implode(',', $this->transLocales->displayLocales ?: []);

        Lang::setLocale($this->transLocales->currentLocale);
        Cookie::queue($this->cookieName(self::COOKIE_LANG_LOCALE), $this->transLocales->currentLocale, 60 * 24 * 365 * 1);
        Cookie::queue($this->cookieName(self::COOKIE_PRIM_LOCALE), $this->transLocales->primaryLocale, 60 * 24 * 365 * 1);
        Cookie::queue($this->cookieName(self::COOKIE_TRANS_LOCALE), $this->transLocales->translatingLocale, 60 * 24 * 365 * 1);
        Cookie::queue($this->cookieName(self::COOKIE_DISP_LOCALES), $displayLocales, 60 * 24 * 365 * 1);

        if (App::runningUnitTests()) {
            return Redirect::to('/');
        }
        return !is_null(Request::header('referer')) ? Redirect::back() : Redirect::to('/');
    }

    public function getUsageInfo()
    {
        $group = Request::get('group');
        $reset = Request::get('reset-usage-info');
        $show = Request::get('show-usage-info');

        // need to store this so that it can be displayed again
        Cookie::queue($this->cookieName(self::COOKIE_SHOW_USAGE), $show, 60 * 24 * 365 * 1);

        if ($reset) {
            // TODO: add show usage info to view variables so that a class can be added to keys that have no usage info
            // need to clear the usage information
            $this->manager->clearUsageCache(true, $group);
        }

        if (App::runningUnitTests()) {
            return Redirect::to('/');
        }
        return !is_null(Request::header('referer')) ? Redirect::back() : Redirect::to('/');
    }

    public function getTransFilters()
    {
        $filter = null;
        $regex = null;

        if (Request::has('filter')) {
            $filter = Request::get("filter");
            $this->transFilters['filter'] = $filter;
        }

        if (Request::has('regex')) {
            $regex = Request::get("regex", "");
            $this->transFilters['regex'] = $regex;
        }

        Cookie::queue($this->cookieName(self::COOKIE_TRANS_FILTERS), json_encode($this->transFilters), 60 * 24 * 365 * 1);

        if (Request::wantsJson()) {
            return Response::json(array(
                'status' => 'ok',
                'transFilters' => $this->transFilters,
            ));
        }

        return !is_null(Request::header('referer')) ? Redirect::back() : Redirect::to('/');
    }

    public function postAddSuffixedKeys($group)
    {
        if (Gate::allows(Manager::ABILITY_ADMIN_TRANSLATIONS)) {
            if (!in_array($group, $this->manager->config(Manager::EXCLUDE_GROUPS_KEY)) && $this->manager->config('admin_enabled')) {
                $keys = explode("\n", trim(Request::get('keys')));
                $suffixes = explode("\n", trim(Request::get('suffixes')));
                $group = explode('::', $group, 2);
                $namespace = '*';
                if (count($group) > 1) $namespace = array_shift($group);
                $group = $group[0];

                $this->manager->setWebUI(true); // we want these to create json keys
                foreach ($keys as $key) {
                    $key = trim($key);
                    if ($group && $key) {
                        if ($suffixes) {
                            foreach ($suffixes as $suffix) {
                                $this->manager->missingKey($namespace, $group, $key . trim($suffix), null, false, false);
                            }
                        } else {
                            $this->manager->missingKey($namespace, $group, $key, null, false, false);
                        }
                    }
                }
                $this->manager->setWebUI(false); // we no more key creation for json
            }
        }
        //Session::flash('_old_data', Request::except('keys'));
        return Redirect::back()->withInput();
    }

    public function postDeleteSuffixedKeys($group)
    {
        if (Gate::allows(Manager::ABILITY_ADMIN_TRANSLATIONS)) {
            if (!in_array($group, $this->manager->config(Manager::EXCLUDE_GROUPS_KEY)) && $this->manager->config('admin_enabled')) {
                $keys = explode("\n", trim(Request::get('keys')));
                $suffixes = explode("\n", trim(Request::get('suffixes')));

                if (count($suffixes) === 1 && $suffixes[0] === '') $suffixes = [];

                foreach ($keys as $key) {
                    $key = trim($key);
                    if ($group && $key) {
                        if ($suffixes) {
                            foreach ($suffixes as $suffix) {
                                //$this->getTranslation()->where('group', $group)->where('key', $key . trim($suffix))->delete();
                                $result = $this->translatorRepository->updateIsDeletedByGroupAndKey($group, $key . trim($suffix), 1);
                            }
                        } else {
                            //$this->getTranslation()->where('group', $group)->where('key', $key)->delete();
                            $result = $this->translatorRepository->updateIsDeletedByGroupAndKey($group, $key, 1);
                        }
                    }
                }
            }
        }
        return Redirect::back()->withInput();
    }

    public function getKeyop($group, $op = 'preview')
    {
        return $this->keyOp($group, $op);
    }

    protected function keyOp($group, $op = 'preview')
    {
        $errors = [];
        $keymap = [];
        $this->logSql = 1;
        $this->sqltraces = [];
        $userLocales = $this->transLocales->userLocales;

        if ($userLocales && !in_array($group, $this->manager->config(Manager::EXCLUDE_GROUPS_KEY)) && $this->manager->config('admin_enabled')) {
            $srckeys = explode("\n", trim(Request::get('srckeys')));
            $dstkeys = explode("\n", trim(Request::get('dstkeys')));

            array_walk($srckeys, function (&$val, $key) use (&$srckeys) {
                $val = trim($val);
                if ($val === '') unset($srckeys[$key]);
            });

            array_walk($dstkeys, function (&$val, $key) use (&$dstkeys) {
                $val = trim($val);
                if ($val === '') unset($dstkeys[$key]);
            });

            if (!$group) {
                $errors[] = trans($this->packagePrefix . 'messages.keyop-need-group');
            } else if (count($srckeys) !== count($dstkeys) && ($op === 'copy' || $op === 'move' || count($dstkeys))) {
                $errors[] = trans($this->packagePrefix . 'messages.keyop-count-mustmatch');
            } else if (!count($srckeys)) {
                $errors[] = trans($this->packagePrefix . 'messages.keyop-need-keys');
            } else {
                if (!count($dstkeys)) {
                    $dstkeys = array_fill(0, count($srckeys), null);
                }

                $keys = array_combine($srckeys, $dstkeys);
                $hadErrors = false;

                foreach ($keys as $src => $dst) {
                    $keyerrors = [];

                    if ($dst !== null) {
                        if ((substr($src, 0, 1) === '*') !== (substr($dst, 0, 1) === '*')) {
                            $keyerrors[] = trans($this->packagePrefix . 'messages.keyop-wildcard-mustmatch');
                        }

                        if ((substr($src, -1, 1) === '*') !== (substr($dst, -1, 1) === '*')) {
                            $keyerrors[] = trans($this->packagePrefix . 'messages.keyop-wildcard-mustmatch');
                        }

                        if ((substr($src, 0, 1) === '*') && (substr($src, -1, 1) === '*')) {
                            $keyerrors[] = trans($this->packagePrefix . 'messages.keyop-wildcard-once');
                        }
                    }

                    if (!empty($keyerrors)) {
                        $hadErrors = true;
                        $keymap[$src] = ['errors' => $keyerrors, 'dst' => $dst,];
                        continue;
                    }

                    list($srcgrp, $srckey) = self::keyGroup($group, $src);
                    list($dstgrp, $dstkey) = $dst === null ? [null, null] : self::keyGroup($group, $dst);

                    $rows = $this->translatorRepository->selectKeys($src, $dst, $userLocales, $srcgrp, $srckey, $dstkey, $dstgrp);

                    $keymap[$src] = ['dst' => $dst, 'rows' => $rows];
                }

                if (!$hadErrors && ($op === 'copy' || $op === 'move' || $op === 'delete')) {
                    foreach ($keys as $src => $dst) {
                        $rows = $keymap[$src]['rows'];

                        $rowids = array_reduce($rows, function ($carry, $row) {
                            return $carry . ',' . $row->id;
                        }, '');
                        $rowids = substr($rowids, 1);

                        list($srcgrp, $srckey) = self::keyGroup($group, $src);
                        if ($op === 'move') {
                            foreach ($rows as $row) {
                                if ($this->isLocaleEnabled($row->locale)) {
                                    list($dstgrp, $dstkey) = self::keyGroup($row->dstgrp, $row->dst);
                                    $to_delete = $this->translatorRepository->selectToDeleteTranslations($dstgrp, $dstkey, $row->locale, $rowids);

                                    if (!empty($to_delete)) {
                                        $to_delete = $to_delete[0]->ids;
                                        if ($to_delete) {
                                            //$this->getConnection()->update("UPDATE ltm_translations SET is_deleted = 1 WHERE id IN ($to_delete)");
                                            // have to delete right away, we will be bringing another key here
                                            // TODO: copy value to new key's saved value
                                            $this->translatorRepository->deleteTranslationsForIds($to_delete);
                                        }
                                    }

                                    $this->translatorRepository->updateGroupKeyStatusById($dstgrp, $dstkey, $row->id);
                                }
                            }
                        } else if ($op === 'delete') {
                            $this->translatorRepository->updateIsDeletedByIds($rowids);
                        } else if ($op === 'copy') {
                            // TODO: split operation into update and insert so that conflicting keys get new values instead of being replaced
                            foreach ($rows as $row) {
                                if ($this->isLocaleEnabled($row->locale)) {
                                    list($dstgrp, $dstkey) = self::keyGroup($row->dstgrp, $row->dst);
                                    $to_delete = $this->translatorRepository->selectToDeleteTranslations($dstgrp, $dstkey, $userLocales, $rowids);

                                    if (!empty($to_delete)) {
                                        $to_delete = $to_delete[0]->ids;
                                        if ($to_delete) {
                                            //$this->getConnection()->update("UPDATE ltm_translations SET is_deleted = 1 WHERE id IN ($to_delete)");
                                            $this->translatorRepository->deleteTranslationsForIds($to_delete);
                                        }
                                    }

                                    $this->translatorRepository->copyKeys($dstgrp, $dstkey, $row->id);
                                }
                            }
                        }
                    }
                }
            }
        } else {
            $errors[] = trans($this->packagePrefix . 'messages.keyops-not-authorized');
        }

        $this->logSql = 0;
        return View::make($this->packagePrefix . 'keyop')
            ->with('controller', ManagerServiceProvider::CONTROLLER_PREFIX . get_class($this))
            ->with('package', $this->package)
            ->with('errors', $errors)
            ->with('keymap', $keymap)
            ->with('op', $op)
            ->with('group', $group);
    }

    protected static function keyGroup($group, $key)
    {
        $prefix = '';
        $gkey = $key;

        if (starts_with($key, 'vnd:') || starts_with($key, 'wbn:')) {
            // these have vendor with . afterwards in the group
            $parts = explode('.', $key, 2);

            if (count($parts) === 2) {
                $prefix = $parts[0] . '.';
                $gkey = $parts[1];
            }
        }

        $parts = explode('.', $gkey, 2);

        if (count($parts) === 1) {
            $tgroup = $group;
            $tkey = $gkey;
        } else {
            $tgroup = $parts[0];
            $tkey = $parts[1];
        }

        return [$prefix . $tgroup, $tkey];
    }

    public function postFind()
    {
        if (Gate::allows(Manager::ABILITY_ADMIN_TRANSLATIONS)) {
            $connection = Request::get('connectionName');
            $this->useConnection($connection);

            $numFound = $this->manager->findTranslations();

            return Response::json(array('status' => 'ok', 'counter' => (int)$numFound));
        }
        return $this->respondNotAdmin(false);
    }
    
    /**
     * @param $connection
     * @param $callback callable
     */
    public function useConnection($connection, $callback = null)
    {
        $normalize = false;
        $resolvedConnection = $this->manager->getResolvedConnectionName($connection);
        if ($resolvedConnection != $this->getConnectionName()) {
            // changed
            $this->manager->setConnectionName($connection);
            $this->initTranslationLocales();
            $normalize = true;
        }

        /** @var callable $callback */
        if ($callback) {
            $callback();
            $normalize = true;
        }

        if ($normalize) {
            $this->normalizeTranslationLocales();
        }
    }
    
    public function postCopyKeys($group)
    {
        return $this->keyOp($group, 'copy');
    }

    public function postMoveKeys($group)
    {
        return $this->keyOp($group, 'move');
    }

    public function postDeleteKeys($group)
    {
        return $this->keyOp($group, 'delete');
    }

    public function postPreviewKeys($group)
    {
        return $this->keyOp($group, 'preview');
    }

    public function postDeleteAll($group)
    {
        if ($group && $group !== '*') {
            $this->manager->truncateTranslations($group);
            return Response::json(array('status' => 'ok', 'counter' => (int)0));
        }
        return $this->respondMissingGroup();
    }

    public function postShowSource($group, $key)
    {
        $key = decodeKey($key);
        $results = '';
        if (!in_array($group, $this->manager->config(Manager::EXCLUDE_GROUPS_KEY)) && $this->manager->config('admin_enabled')) {
            $result = $this->translatorRepository->selectSourceByGroupAndKey($group, $key);

            foreach ($result as $item) {
                $results .= $item->source;
            }
        }

        foreach (explode("\n", $results) as $ref) {
            if ($ref != '') {
                $refs[] = $ref;
            }
        }
        sort($refs);
        return array('status' => 'ok', 'result' => $refs, 'key_name' => "$group.$key");
    }

    public function postImport($group)
    {
        $replace = Request::get('replace', false);
        // the group publish form has no select for replace, it is always false, react does have it
        $counter = $this->manager->importTranslations($group === '*' ? $replace : ($this->manager->inDatabasePublishing() == 1 ? 0 : $replace)
            , $group === '*' ? null : [$group]);
        return Response::json(array('status' => 'ok', 'counter' => $counter));
    }

    public function getImport()
    {
        $replace = Request::get('replace', false);
        $group = Request::get('group', '*');
        $this->manager->clearErrors();
        $counter = $this->manager->importTranslations($group === '*' ? $replace : ($this->manager->inDatabasePublishing() == 1 ? 0 : $replace)
            , $group === '*' ? null : [$group]);
        $errors = $this->manager->errors();
        return Response::json(array('status' => 'ok', 'counter' => $counter, 'errors' => $errors));
    }

    /**
     * Test for postPublish
     * @param $group
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPublish($group)
    {
        if ($group && $group != '*') {
            $this->manager->exportTranslations($group);
        } else {
            $this->manager->exportAllTranslations();
        }
        $errors = $this->manager->errors();

        event(new TranslationsPublished($group, $errors));
        return Response::json(array('status' => $errors ? 'errors' : 'ok', 'errors' => $errors));
    }

    public function postPublish($group)
    {
        if ($group) {
            if ($group != '*') {
                $this->manager->exportTranslations($group);
            } else {
                $this->manager->exportAllTranslations();
            }
            $errors = $this->manager->errors();

            event(new TranslationsPublished($group, $errors));
            return Response::json(array('status' => $errors ? 'errors' : 'ok', 'errors' => $errors));
        }
        return $this->respondMissingGroup();
    }

    public function getZippedTranslations($group = null)
    {
        // disable gzip compression of this page, this causes wrapping of the zip file in gzip format
        // does not work the zip is still gzip compressed
        if (ini_get('zlib.output_compression')) {
            ini_set('zlib.output_compression', 'Off');
            //\Log::info("after turning off zlib.compression current setting " . ini_get('zlib.output_compression'));
        }

        $file = $this->manager->zipTranslations($group);
        if ($group && $group !== '*') {
            $zip_name = "Translations_${group}_"; // Zip name
        } else {
            $zip_name = "Translations_"; // Zip name
        }

        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: attachment; filename="' . $zip_name . date('Ymd-His') . '.zip"');
        header('Content-Transfer-Encoding: binary');

        ob_clean();
        flush();
        readfile($file);
        unlink($file);
        //\Log::info("sending file, zlib.compression current setting " . ini_get('zlib.output_compression'));
    }

    public function postTransFilters()
    {
        $filter = null;
        $regex = null;

        if (Request::has('filter')) {
            $filter = Request::get("filter");
            $this->transFilters['filter'] = $filter;
        }

        if (Request::has('regex')) {
            $regex = Request::get("regex", "");
            $this->transFilters['regex'] = $regex;
        }

        Cookie::queue($this->cookieName(self::COOKIE_TRANS_FILTERS), json_encode($this->transFilters), 60 * 24 * 365 * 1);

        if (Request::wantsJson()) {
            return Response::json(array(
                'status' => 'ok',
                'transFilters' => $this->transFilters,
            ));
        }

        return Response::json(array('status' => 'ok'));
    }

    public static function webRoutes()
    {
        Route::get('view/{group?}', '\\Vsch\\TranslationManager\\Controller@getView');

        //deprecated: Route::controller('admin/translations', '\\Vsch\\TranslationManager\\Controller');
        Route::get('/', '\\Vsch\\TranslationManager\\Controller@getIndex');
        Route::get('connection', '\\Vsch\\TranslationManager\\Controller@getConnection');
        Route::get('index', '\\Vsch\\TranslationManager\\Controller@getIndex');
        Route::get('interface_locale', '\\Vsch\\TranslationManager\\Controller@getTranslationLocales');
        Route::get('keyop/{group}/{op?}', '\\Vsch\\TranslationManager\\Controller@getKeyop');
        Route::get('search', '\\Vsch\\TranslationManager\\Controller@getSearch');
        Route::get('toggle_in_place_edit', '\\Vsch\\TranslationManager\\Controller@getToggleInPlaceEdit');
        Route::get('toggle_show_unpublished', '\\Vsch\\TranslationManager\\Controller@getToggleShowUnpublished');
        Route::get('translation', '\\Vsch\\TranslationManager\\Controller@getTranslation');
        Route::get('usage_info', '\\Vsch\\TranslationManager\\Controller@getUsageInfo');
        Route::get('view/{group?}', '\\Vsch\\TranslationManager\\Controller@getView');
        Route::get('trans_filters', '\\Vsch\\TranslationManager\\Controller@getTransFilters');
        Route::post('find', '\\Vsch\\TranslationManager\\Controller@postFind');
        Route::post('yandex_key', '\\Vsch\\TranslationManager\\Controller@postYandexKey');
        Route::post('aws_access_key', '\\Vsch\\TranslationManager\\Controller@postAWSConfig');
        //Route::post('azure_access_key', '\\Vsch\\TranslationManager\\Controller@postAZUREConfig');
        Route::post('delete_suffixed_keys/{group}', '\\Vsch\\TranslationManager\\Controller@postDeleteSuffixedKeys');
        Route::post('add/{group}', '\\Vsch\\TranslationManager\\Controller@postAddSuffixedKeys');
        Route::post('show_source/{group}/{key}', '\\Vsch\\TranslationManager\\Controller@postShowSource');
        Route::post('delete/{group}/{key}', '\\Vsch\\TranslationManager\\Controller@postDelete');
        Route::post('delete_all/{group}', '\\Vsch\\TranslationManager\\Controller@postDeleteAll');
        Route::post('publish/{group}', '\\Vsch\\TranslationManager\\Controller@postPublish');
        Route::post('import/{group}', '\\Vsch\\TranslationManager\\Controller@postImport');
        Route::get('zipped_translations/{group?}', '\\Vsch\\TranslationManager\\Controller@getZippedTranslations');
        Route::get('publish/{group}', '\\Vsch\\TranslationManager\\Controller@getPublish');
        Route::get('import', '\\Vsch\\TranslationManager\\Controller@getImport');

        // shared web and api urls 
        // TODO: migrate to Rest Controller for implementation
        Route::post('edit/{group}', '\\Vsch\\TranslationManager\\Controller@postEdit');
        Route::post('undelete/{group}/{key}', '\\Vsch\\TranslationManager\\Controller@postUndelete');
        Route::post('user_locales', '\\Vsch\\TranslationManager\\Controller@postUserLocales');

        // TODO: implement a json api for wild-card key ops
        Route::post('delete_keys/{group}', '\\Vsch\\TranslationManager\\Controller@postDeleteKeys');
        Route::post('copy_keys/{group}', '\\Vsch\\TranslationManager\\Controller@postCopyKeys');
        Route::post('move_keys/{group}', '\\Vsch\\TranslationManager\\Controller@postMoveKeys');
        Route::post('preview_keys/{group}', '\\Vsch\\TranslationManager\\Controller@postPreviewKeys');
    }

    public static function routes($disableReactUI)
    {
        self::webRoutes();
    }


    // TODO: these are shared and they all need extra param for connectionName from the React UI
    public function postEdit($group)
    {
        if (!in_array($group, $this->manager->config(Manager::EXCLUDE_GROUPS_KEY))) {
            $name = Request::get('name');
            $value = Request::get('value');

            list($locale, $key) = explode('|', $name, 2);
            if ($this->isLocaleEnabled($locale)) {
                $translation = $this->manager->firstOrNewTranslation(array(
                    'locale' => $locale,
                    'group' => $group,
                    'key' => $key,
                ));

                $markdownSuffix = $this->manager->config(Manager::MARKDOWN_KEY_SUFFIX);
                $isMarkdownKey = $markdownSuffix != '' && ends_with($key, $markdownSuffix) && $key !== $markdownSuffix;

                if (!$isMarkdownKey) {
                    // strip off trailing spaces and eol's and &nbsps; that seem to be added when multiple spaces are entered in the x-editable textarea
                    $value = trim(str_replace("\xc2\xa0", ' ', $value));
                }

                $value = $value !== '' ? $value : null;

                $translation->value = $value;
                $translation->status = (($translation->isDirty() && $value != $translation->saved_value) ? Translation::STATUS_CHANGED : Translation::STATUS_SAVED);
                $translation->save();

                if ($isMarkdownKey) {
                    $markdownKey = $key;
                    $markdownValue = $value;

                    $key = substr($markdownKey, 0, -strlen($markdownSuffix));

                    $translation = $this->manager->firstOrNewTranslation(array(
                        'locale' => $locale,
                        'group' => $group,
                        'key' => $key,
                    ));

                    $value = $markdownValue !== null ? \Markdown::convertToHtml(str_replace("\xc2\xa0", ' ', $markdownValue)) : null;

                    $translation->value = $value;
                    $translation->status = (($translation->isDirty() && $value != $translation->saved_value) ? Translation::STATUS_CHANGED : Translation::STATUS_SAVED);
                    $translation->save();
                }
            }
        }
        return array('status' => 'ok');
    }

    public function postDelete($group, $key)
    {
        if (Gate::allows(Manager::ABILITY_ADMIN_TRANSLATIONS)) {
            $key = decodeKey($key);
            if (!in_array($group, $this->manager->config(Manager::EXCLUDE_GROUPS_KEY)) && $this->manager->config('admin_enabled')) {
                //$this->getTranslation()->where('group', $group)->where('key', $key)->delete();
                $result = $this->translatorRepository->updateIsDeletedByGroupAndKey($group, $key, 1);
                return Response::json(array('status' => 'ok'));
            }
            return Response::json(array('status' => 'error', 'error' => 'missing group', 'counter' => (int)0));
        }
        return $this->respondNotAdmin(false);
    }

    public function postUndelete($group, $key)
    {
        if (Gate::allows(Manager::ABILITY_ADMIN_TRANSLATIONS)) {
            $key = decodeKey($key);
            if (!in_array($group, $this->manager->config(Manager::EXCLUDE_GROUPS_KEY)) && $this->manager->config('admin_enabled')) {
                //$this->getTranslation()->where('group', $group)->where('key', $key)->delete();
                $result = $this->translatorRepository->updateIsDeletedByGroupAndKey($group, $key, 0);
                return Response::json(array('status' => 'ok'));
            }
            return Response::json(array('status' => 'error', 'error' => 'missing group', 'counter' => (int)0));
        }
        return $this->respondNotAdmin(false);
    }

    public function postYandexKey()
    {
        return Response::json(array(
            'status' => 'ok',
            'yandex_key' => $this->manager->config('yandex_translator_key', null),
        ));
    }

    public function postAWSConfig()
    {
        return Response::json(array(
            'status' => 'ok',
            'aws_access_key' => $this->manager->config('aws_access_key', null),
            'aws_secret_access_key' => $this->manager->config('aws_secret_access_key', null),
            'aws_endpoint' => $this->manager->config('aws_endpoint', null),
            'aws_region' => $this->manager->config('aws_region', null),
        ));
    }

    public function postAZUREConfig()
    {
        return Response::json(array(
            'status' => 'ok',
            'azure_access_key' => $this->manager->config('azure_access_key', null),
            'azure_endpoint' => $this->manager->config('azure_endpoint', null),
            'azure_region' => $this->manager->config('azure_region', null),
        ));
    }
    
    public function postUserLocales()
    {
        $user_id = Request::get("pk");
        $values = Request::get("value") ?: [];
        $userLocale = new UserLocales();

        $connection_name = $this->getConnectionName();
        /* @var $userLocales UserLocales */
        $userLocales = $userLocale->on($connection_name)->where('user_id', $user_id)->first();
        if (!$userLocales) {
            $userLocales = $userLocale;
            $userLocales->setConnection($connection_name);
            $userLocales->user_id = $user_id;
        }

        $userLocales->setConnection($connection_name);
        $userLocales->locales = implode(",", $values);
        $userLocales->save();
        $errors = "";
        return Response::json(array('status' => $errors ? 'error' : 'ok', 'error' => $errors));
    }

    /**
     * @param $displayLocales array
     * @return array
     */
    private function computeSummary($displayLocales): array
    {
        $stats = $this->translatorRepository->stats($displayLocales);

        // returned result set lists missing, changed, group, locale
        $summary = [];
        foreach ($stats as $stat) {
            if (!isset($summary[$stat->group])) {
                $item = $summary[$stat->group] = new \stdClass();
                $item->missing = '';
                $item->changed = '';
                $item->cached = '';
                $item->deleted = '';
                $item->group = $stat->group;
            }

            $item = $summary[$stat->group];
            if ($stat->missing) {
                $item->missing .= $stat->locale . ":" . $stat->missing . " ";
            }

            if ($stat->changed) {
                $item->changed .= $stat->locale . ":" . $stat->changed . " ";
            }

            if ($stat->cached) {
                $item->cached .= $stat->locale . ":" . $stat->cached . " ";
            }

            if ($stat->deleted) {
                $item->deleted .= $stat->locale . ":" . $stat->deleted . " ";
            }
        }
        return $summary;
    }

    /**
     * @param $primaryLocale
     * @param $translatingLocale
     * @return array
     */
    private function computeMismatches($primaryLocale, $translatingLocale): array
    {
        // get mismatches
        $mismatches = $this->translatorRepository->findMismatches($this->transLocales->displayLocales, $primaryLocale, $translatingLocale);

        $key = '';
        $translatingList = [];
        $primaryList = [];
        $translatingBases = [];    // by key
        $primaryBases = [];    // by key
        $extra = new \stdClass();
        $extra->key = '';
        $mismatches[] = $extra;
        foreach ($mismatches as $mismatch) {
            if ($mismatch->key !== $key) {
                if ($key) {
                    // process diff for key
                    $translatingText = '';
                    $primaryText = '';
                    if (count($primaryList) > 1) {
                        $maxPrimary = 0;
                        foreach ($primaryList as $en => $count) {
                            if ($maxPrimary < $count) {
                                $maxPrimary = $count;
                                $primaryText = $en;
                            }
                        }
                        $primaryBases[$key] = $primaryText;
                    } else {
                        $primaryText = array_keys($primaryList)[0];
                        $primaryBases[$key] = $primaryText;
                    }
                    if (count($translatingList) > 1) {
                        $maxTranslating = 0;
                        foreach ($translatingList as $ru => $count) {
                            if ($maxTranslating < $count) {
                                $maxTranslating = $count;
                                $translatingText = $ru;
                            }
                        }
                        $translatingBases[$key] = $translatingText;
                    } else {
                        $translatingText = array_keys($translatingList)[0];
                        $translatingBases[$key] = $translatingText;
                    }
                }
                $key = $mismatch->key;
                $translatingList = [];
                $primaryList = [];
            }

            if ($mismatch->key === '') break;

            if (!isset($primaryList[$mismatch->en])) {
                $primaryList[$mismatch->en] = 1;
            } else {
                $primaryList[$mismatch->en]++;
            }

            if (!isset($translatingList[$mismatch->ru])) {
                $translatingList[$mismatch->ru] = 1;
            } else {
                $translatingList[$mismatch->ru]++;
            }
        }

        array_splice($mismatches, count($mismatches) - 1, 1);

        foreach ($mismatches as $mismatch) {
            $mismatch->en_value = $mismatch->ru;
            $mismatch->en = mb_renderDiffHtml($primaryBases[$mismatch->key], $mismatch->en);
            $mismatch->ru_value = $mismatch->ru;
            $mismatch->ru = mb_renderDiffHtml($translatingBases[$mismatch->key], $mismatch->ru);
        }
        return $mismatches;
    }

    /**
     * @return array|null
     */
    private function computeUserList()
    {
        $userList = [];
        $admin_translations = Gate::allows(Manager::ABILITY_ADMIN_TRANSLATIONS);
        if ($admin_translations && $this->manager->areUserLocalesEnabled()) {
            $connection_name = $this->getConnectionName();
            $userListProvider = $this->manager->getUserListProvider($connection_name);
            if ($userListProvider !== null && is_a($userListProvider, "Closure")) {
                $userList = null;
                $haveUsers = $userListProvider(Auth::user(), $this->manager->getUserListConnection($connection_name), $userList);
                if ($haveUsers && is_array($userList)) {
                    /* @var $connection_name string */
                    /* @var $query  \Illuminate\Database\Eloquent\Builder */
                    $userLocalesModel = new UserLocales();
                    $userLocaleList = $userLocalesModel->on($connection_name)->get();

                    $userLocales = [];
                    foreach ($userLocaleList as $userLocale) {
                        $userLocales[$userLocale->user_id] = $userLocale;
                    }

                    foreach ($userList as $user) {
                        if (array_key_exists($user->id, $userLocales)) {
                            $user->locales = $userLocales[$user->id]->locales;
                        } else {
                            $user->locales = '';
                        }
                    }
                } else {
                    $userList = [];
                }
            }
        }
        return $userList;
    }

    /**
     * @param $searchText
     * @param array|null $displayLocales
     * @return array
     */
    private function computeSearch($searchText, $displayLocales = null): array
    {
        if (trim($searchText) === '') $translations = [];
        else {
            $displayWhere = $displayLocales ? " AND locale IN ('" . implode("','", $displayLocales) . "') AND locale <> 'json'" : " AND locale <> 'json'";

            if (strpos($searchText, '%') === false) $searchText = "%$searchText%";

            // need to fill-in missing locale's that match the key
            $translations = $this->translatorRepository->searchByRequest($searchText, $displayWhere, 500);
            // search should not adjust this but exclude it
            //            foreach ($translations as $t) {
            //                $this->adjustJsonLocaleTranslation($t);
            //            }
        }
        return $translations;
    }

    /**
     * @param $t
     */
    public function adjustJsonLocaleTranslation($t, $primaryValue = null): void
    {
        if ($this->isJsonKeyLocale($t)) {
            if ($t->value === '' || $t->value === null) {
                if ($primaryValue && $this->manager->isNewJsonKeyFromPrimaryLocale()) {
                    $t->value = $primaryValue;
                } else if ($t->saved_value === '' || $t->saved_value === null) {
                    $t->value = $t->key;
                } else {
                    $t->value = $t->saved_value;
                }
            }
        }
    }

    /**
     * @param $translations
     * @param $key
     * @param $locale
     * @return string|null
     */
    private function getPrimaryValue($translations, $key, $locale)
    {
        if (array_key_exists($key, $translations)) {
            $locales = $translations[$key];
            if (array_key_exists($locale, $locales)) {
                return $locales[$locale]->value;
            }
        }
        return null;
    }

    private function respondNotAdmin($pretty)
    {
        abort(403, trans($this->package . '::messages.error-no-admin-rights'));
        //        return Response::json(array('status' => 'error', 'error' => 'no admin rights'), 401, [], JSON_UNESCAPED_SLASHES | $pretty);
    }

    private function respondMissingKeyParam()
    {
        abort(400, trans($this->package . '::messages.error-no-key-param'));
        //        return Response::json(array('status' => 'error', 'error' => 'request is missing a key parameter'), 400, [], JSON_UNESCAPED_SLASHES);
    }

    private function respondMissingGroup()
    {
        abort(400, trans($this->package . '::messages.error-no-group-param'));
        //        return Response::json(array('status' => 'ok', 'error' => 'missing group', 'counter' => (int)0));
    }

    private function respondGroupExcluded($pretty)
    {
        abort(400, trans($this->package . '::messages.error-group-excluded'));
        //        return Response::json(array('status' => 'error', 'error' => 'group excluded'), 403, [], JSON_UNESCAPED_SLASHES | $pretty);
    }
}
