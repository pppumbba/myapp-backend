<?php

use App\Http\Controllers\Api\V1\AccessController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChartController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\ExcelImportController;
use App\Http\Controllers\Api\V1\ExcelStructureController;
use App\Http\Controllers\Api\V1\GoogleAuthController;
use App\Http\Controllers\Api\V1\LanguageController;
use App\Http\Controllers\Api\V1\MatrixController;
use App\Http\Controllers\Api\V1\MetaActionController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\MetaCopyController;
use App\Http\Controllers\Api\V1\MetaDataController;
use App\Http\Controllers\Api\V1\MetaParamController;
use App\Http\Controllers\Api\V1\MetaTransferController;
use App\Http\Controllers\Api\V1\MetaTypeController;
use App\Http\Controllers\Api\V1\MetaUsageController;
use App\Http\Controllers\Api\V1\MetaVersionController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationRuleController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\PatchController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\ProcessFlowController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicSiteController;
use App\Http\Controllers\Api\V1\QueryDesignController;
use App\Http\Controllers\Api\V1\RecordLogController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\RoleWorkspaceController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\SessionValueController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Controllers\Api\V1\StageQueryController;
use App\Http\Controllers\Api\V1\StructureController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\ThemeController;
use App\Http\Controllers\Api\V1\TranslationController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WidgetController;
use App\Http\Controllers\Api\V1\WorkflowController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::get('/auth/providers', [GoogleAuthController::class, 'providers']);
Route::post('/auth/google/exchange', [GoogleAuthController::class, 'exchange'])->middleware('throttle:login');

// Attached files: public ones open for anyone, the rest check the token (ContentController).
Route::get('/contents/{content}', [ContentController::class, 'show'])->whereNumber('content')->middleware('throttle:120,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/meta-types', [MetaTypeController::class, 'index']);
    Route::get('/metas/options', [MetaController::class, 'options']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // The signed-in user's own profile.
    Route::get('/user', [ProfileController::class, 'show']);
    Route::put('/user', [ProfileController::class, 'update']);
    Route::put('/user/theme', [ThemeController::class, 'mine']);
    Route::put('/user/locale', [LanguageController::class, 'mine']);
    Route::get('/languages', [LanguageController::class, 'index']);
    Route::post('/languages', [LanguageController::class, 'store']);
    Route::put('/languages/{language}', [LanguageController::class, 'update']);
    Route::delete('/languages/{language}', [LanguageController::class, 'destroy']);
    Route::get('/metas/{meta}/translations', [TranslationController::class, 'show']);
    Route::get('/metas/{meta}/translations/export', [TranslationController::class, 'export']);
    Route::put('/metas/{meta}/translations', [TranslationController::class, 'save'])->middleware('meta.version');
    Route::post('/metas/{meta}/translations/import', [TranslationController::class, 'import'])->middleware('meta.version');
    Route::get('/themes', [ThemeController::class, 'index']);
    Route::put('/metas/{meta}/theme', [ThemeController::class, 'save'])->middleware('meta.version');
    Route::put('/user/password', [ProfileController::class, 'password'])->middleware('throttle:password');
    Route::get('/user/session', [SessionValueController::class, 'mine']);
    Route::post('/user/session/refresh', [SessionValueController::class, 'refresh']);
    Route::post('/session-values/test', [SessionValueController::class, 'test']);
    Route::apiResource('session-values', SessionValueController::class)->except('show');

    // The signed-in user's notifications.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

    // Admins only: roles, who has them, and what each role may do.
    Route::apiResource('roles', RoleController::class)->except('show');
    // One role's workspace: its people, its abilities on every meta and status, and its log.
    Route::get('/roles/{role}/workspace', [RoleWorkspaceController::class, 'show']);
    Route::put('/roles/{role}/users', [RoleWorkspaceController::class, 'users']);
    Route::put('/roles/{role}/metas', [RoleWorkspaceController::class, 'metas']);
    Route::put('/roles/{role}/statuses', [RoleWorkspaceController::class, 'statuses']);
    Route::get('/roles/{role}/log', [RoleWorkspaceController::class, 'log']);
    Route::get('/meta-tree', [RoleWorkspaceController::class, 'tree']);
    Route::get('/users', [UserController::class, 'index']);
    Route::put('/users/{user}/roles', [UserController::class, 'roles']);
    Route::get('/metas/{meta}/permissions', [PermissionController::class, 'meta']);
    Route::put('/metas/{meta}/permissions', [PermissionController::class, 'saveMeta'])->middleware('meta.version');
    Route::get('/workflows/{meta}/permissions', [PermissionController::class, 'workflow']);
    Route::put('/workflows/{meta}/permissions', [PermissionController::class, 'saveWorkflow']);

    // meta.version: a change to a meta or its parts keeps how it was as a version (SavesMetaVersion).
    Route::apiResource('metas', MetaController::class)->except('show')->middlewareFor('update', 'meta.version');
    // Tags saying what metas are for: anyone adds one, admins rename and remove them.
    Route::apiResource('tags', TagController::class)->except('show');
    Route::apiResource('metas.params', MetaParamController::class)->shallow()->except('index')->middlewareFor(['store', 'update', 'destroy'], 'meta.version');

    // A datagrid's toolbar buttons and the forms they open.
    Route::middleware('meta.version')->group(function () {
        Route::post('/metas/{meta}/actions', [MetaActionController::class, 'store']);
        Route::put('/actions/{action}', [MetaActionController::class, 'update']);
        Route::delete('/actions/{action}', [MetaActionController::class, 'destroy']);
    });

    // Workflows: the designer's statuses and transitions, and moving records through them.
    Route::middleware('meta.version')->group(function () {
        Route::put('/workflows/{meta}/layout', [WorkflowController::class, 'layout']);
        Route::post('/workflows/{meta}/statuses', [WorkflowController::class, 'storeStatus']);
        Route::put('/wfm-statuses/{status}', [WorkflowController::class, 'updateStatus']);
        Route::delete('/wfm-statuses/{status}', [WorkflowController::class, 'destroyStatus']);
        Route::post('/workflows/{meta}/transitions', [WorkflowController::class, 'storeTransition']);
        Route::put('/wfm-transitions/{transition}', [WorkflowController::class, 'updateTransition']);
        Route::delete('/wfm-transitions/{transition}', [WorkflowController::class, 'destroyTransition']);
    });
    Route::get('/workflows/{meta}/records/{id}', [WorkflowController::class, 'record'])->whereNumber('id');
    Route::post('/workflows/{meta}/records/{id}/move', [WorkflowController::class, 'move'])->whereNumber('id');

    // Stage queries: SQL that changes data, run with named parameters.
    Route::post('/metas/{meta}/run', [StageQueryController::class, 'run']);

    // Charts: their numbers, and for the builder a preview and the columns to pick from.
    Route::post('/metas/{meta}/chart/preview', [ChartController::class, 'preview']);
    Route::get('/metas/{meta}/columns', [ChartController::class, 'columns']);

    // Records behind a datagrid or form meta: printing them, their log, changing and deleting them.
    Route::get('/metas/{meta}/data/{id}/pdf', [DocumentController::class, 'record'])->where('id', '[A-Za-z0-9_.:-]+');
    Route::get('/metas/{meta}/data/{id}/log', [RecordLogController::class, 'index'])->whereNumber('id');
    Route::match(['put', 'patch'], '/metas/{meta}/data/{id}', [MetaDataController::class, 'update'])->where('id', '[A-Za-z0-9_.:-]+');
    Route::delete('/metas/{meta}/data/{id}', [MetaDataController::class, 'destroy'])->where('id', '[A-Za-z0-9_.:-]+');

    // Report metas as PDF documents, laid out in the document designer.
    Route::get('/metas/{meta}/layout', [DocumentController::class, 'show']);
    Route::put('/metas/{meta}/layout', [DocumentController::class, 'save'])->middleware('meta.version');
    Route::post('/metas/{meta}/layout/preview', [DocumentController::class, 'preview']);
    Route::post('/metas/{meta}/documents/{param}', [DocumentController::class, 'fill']);
    Route::get('/metas/{meta}/pdf', [DocumentController::class, 'many']);
    Route::post('/metas/{meta}/document-assets', [DocumentController::class, 'upload']);
    Route::get('/metas/{meta}/document-assets/{name}', [DocumentController::class, 'asset']);

    // Excel: any data meta's records as a file, and excel_import metas loading files into a table.
    Route::get('/metas/{meta}/export', [MetaDataController::class, 'export']);
    Route::get('/metas/{meta}/import/template', [ExcelImportController::class, 'template']);
    Route::post('/metas/{meta}/import', [ExcelImportController::class, 'store']);

    // The page builder: the widget library, and pages laid out from widget metas.
    Route::apiResource('widgets', WidgetController::class)->except('show');
    Route::put('/metas/{meta}/page', [PageController::class, 'save'])->middleware('meta.version');
    // The builder studio: an app's menu, a grid's columns, a form's fields or a tab set's tabs in one save.
    Route::put('/metas/{meta}/structure', [StructureController::class, 'save'])->middleware('meta.version');
    Route::put('/metas/{meta}/app', [StructureController::class, 'saveApp'])->middleware('meta.version');

    // The notification builder: what a notification meta would send, sending it, and its log.
    Route::get('/metas/{meta}/notification/preview', [NotificationRuleController::class, 'preview']);
    Route::post('/metas/{meta}/notification/send', [NotificationRuleController::class, 'send']);
    Route::get('/metas/{meta}/notification/log', [NotificationRuleController::class, 'log']);

    // The query builder: tables and views to drag in, and query metas designed from them (QueryDesign).
    Route::post('/metas/{meta}/copy', [MetaCopyController::class, 'copy']);
    Route::post('/metas/{meta}/replace', [MetaCopyController::class, 'replace'])->middleware('meta.version');
    Route::get('/metas/{meta}/derive', [MetaCopyController::class, 'targets']);
    Route::post('/metas/{meta}/derive', [MetaCopyController::class, 'derive']);

    // Metas to and from a file, for moving them to another installation (MetaTransfer).
    Route::get('/meta-transfer/export', [MetaTransferController::class, 'export']);
    Route::post('/meta-transfer/import', [MetaTransferController::class, 'import']);
    // Where each meta is used (MetaUsage).
    Route::get('/meta-usage', [MetaUsageController::class, 'index']);
    Route::get('/metas/{meta}/usage', [MetaUsageController::class, 'show']);

    // Patches: metas shipped as released files, and files installed here with rollback (Patches).
    Route::get('/patches/changed', [PatchController::class, 'changed']);
    Route::get('/patches', [PatchController::class, 'index']);
    Route::post('/patches', [PatchController::class, 'store']);
    Route::put('/patches/{patch}', [PatchController::class, 'update']);
    Route::delete('/patches/{patch}', [PatchController::class, 'destroy']);
    Route::post('/patches/{patch}/release', [PatchController::class, 'release']);
    Route::get('/patches/{patch}/download', [PatchController::class, 'download']);
    Route::get('/patch-installs', [PatchController::class, 'installs']);
    Route::post('/patch-installs', [PatchController::class, 'install']);
    Route::post('/patch-installs/{install}/rollback', [PatchController::class, 'rollback']);
    Route::get('/patch-installs/{install}/download', [PatchController::class, 'downloadInstall']);

    Route::post('/excel-structure', [ExcelStructureController::class, 'upload']);
    Route::get('/excel-structure/{token}', [ExcelStructureController::class, 'show']);
    Route::post('/excel-structure/{token}/columns', [ExcelStructureController::class, 'columns']);
    Route::post('/excel-structure/{token}/create', [ExcelStructureController::class, 'create']);

    Route::get('/query-builder/tables', [QueryDesignController::class, 'tables']);
    Route::get('/query-builder/tables/{table}', [QueryDesignController::class, 'table']);
    Route::get('/query-builder/sources', [QueryDesignController::class, 'sources']);
    Route::get('/query-builder/sources/{meta}', [QueryDesignController::class, 'source']);
    Route::get('/metas/{meta}/query-design', [QueryDesignController::class, 'show']);
    Route::put('/metas/{meta}/query-design', [QueryDesignController::class, 'save'])->middleware('meta.version');
    Route::post('/metas/{meta}/query-design/preview', [QueryDesignController::class, 'preview']);
    Route::get('/metas/{meta}/query-design/rows', [QueryDesignController::class, 'rows']);
    Route::post('/metas/{meta}/query-design/grid', [QueryDesignController::class, 'grid']);

    // Matrices: a value for each pair of records from two grids (e.g. bidder × item prices).
    Route::put('/metas/{meta}/matrix', [MatrixController::class, 'save']);

    // Version control: saved versions of a meta, comparing them and restoring one.
    Route::get('/meta-versions/deleted', [MetaVersionController::class, 'deleted']);
    Route::get('/metas/{meta}/versions', [MetaVersionController::class, 'index']);
    Route::post('/metas/{meta}/versions', [MetaVersionController::class, 'store']);
    Route::get('/meta-versions/{version}/diff', [MetaVersionController::class, 'diff']);
    Route::post('/meta-versions/{version}/restore', [MetaVersionController::class, 'restore']);

    // Process flows: the forms a form's new record runs next, and the values they take.
    Route::get('/metas/{meta}/process-flow', [ProcessFlowController::class, 'show']);
    Route::put('/metas/{meta}/process-flow', [ProcessFlowController::class, 'save'])->middleware('meta.version');

    // Websites: pages from the page builder with addresses, domains, checks, publishing and visits (Sites).
    Route::get('/sites', [SiteController::class, 'index']);
    Route::post('/sites', [SiteController::class, 'store']);
    Route::get('/sites/{meta}', [SiteController::class, 'show'])->whereNumber('meta');
    Route::put('/sites/{meta}', [SiteController::class, 'save'])->whereNumber('meta')->middleware('meta.version');
    Route::get('/sites/{meta}/preview', [SiteController::class, 'preview']);
    Route::get('/sites/{meta}/stats', [SiteController::class, 'stats']);
    Route::get('/sites/{meta}/forms', [SiteController::class, 'forms']);
    Route::get('/sites/{meta}/pages/{param}/check', [SiteController::class, 'check']);
    Route::get('/sites/{meta}/pages/{param}/releases', [SiteController::class, 'releases']);
    Route::post('/sites/{meta}/pages/{param}/publish', [SiteController::class, 'publish']);
    Route::post('/sites/{meta}/pages/{param}/unpublish', [SiteController::class, 'unpublish']);
    Route::post('/site-releases/{release}/restore', [SiteController::class, 'restore']);
    Route::delete('/site-releases/{release}', [SiteController::class, 'cancel']);

    // Scheduled forms: when each schedule runs, and which form it runs over which grid's rows.
    Route::apiResource('schedules', ScheduleController::class)->except('show');
    Route::post('/schedules/{schedule}/forms', [ScheduleController::class, 'addForm']);
    Route::delete('/meta-schedules/{metaSchedule}', [ScheduleController::class, 'removeForm']);
    Route::post('/schedules/{schedule}/run', [ScheduleController::class, 'run']);
});

/*
 * What running a meta reads and sends, open to guests too: signed in or not, Access decides
 * (public metas may be opened and added to by anyone, e.g. a portal). Guests are rate limited.
 * Registered last, so fixed paths such as /metas/options win over /metas/{meta}.
 */
Route::middleware(['auth.optional', 'throttle:public'])->group(function () {
    Route::get('/user/access', [AccessController::class, 'show']);
    // A website's published page, by its domain or code (PublicSiteController).
    Route::get('/site', [PublicSiteController::class, 'show']);
    Route::get('/metas/{meta}', [MetaController::class, 'show'])->whereNumber('meta');
    Route::get('/metas/{meta}/params', [MetaParamController::class, 'index']);
    Route::get('/metas/{meta}/actions', [MetaActionController::class, 'index']);
    Route::get('/workflows/{meta}', [WorkflowController::class, 'show']);

    // The values a form opens with, from a get meta.
    Route::get('/metas/{meta}/fill', [MetaDataController::class, 'fill']);
    Route::get('/metas/{meta}/chart', [ChartController::class, 'show']);
    Route::get('/metas/{meta}/page', [PageController::class, 'show']);
    Route::get('/metas/{meta}/matrix', [MatrixController::class, 'show']);

    // Records behind a datagrid or form meta.
    Route::get('/metas/{meta}/data', [MetaDataController::class, 'index']);
    Route::get('/metas/{meta}/statement', [MetaDataController::class, 'statement']);
    Route::get('/metas/{meta}/facets', [MetaDataController::class, 'facets']);
    Route::post('/metas/{meta}/data', [MetaDataController::class, 'store']);
    Route::get('/metas/{meta}/autonumber', [MetaDataController::class, 'autonumber']);
    Route::get('/metas/{meta}/data/{id}', [MetaDataController::class, 'show'])->where('id', '[A-Za-z0-9_.:-]+');

    // Files for a form's singlefile and multifile fields, attached to the record when it saves.
    Route::post('/metas/{meta}/files', [ContentController::class, 'store']);
});
