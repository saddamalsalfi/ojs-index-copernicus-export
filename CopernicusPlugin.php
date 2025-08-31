<?php
/**
 * @file plugins/importexport/copernicus/CopernicusPlugin.php
 *
 * Copernicus export plugin (OJS 3.5.0-1)
 * DOAJ-like architecture: thin plugin + dedicated XML builder/validator.
 *
 * - Reads "validateSchema" from GET/POST (default = true)
 * - Passes options to CopernicusXmlBuilder (validation, schema path, etc.)
 * - Keeps templates simple (index.tpl, issues.tpl)
 */

namespace APP\plugins\importexport\copernicus;

use APP\facades\Repo;
use APP\journal\Journal;
use PKP\plugins\ImportExportPlugin;
use PKP\template\PKPTemplateManager;

// Bring in the builder (PSR-4 is not guaranteed inside plugin folders)
require_once __DIR__ . '/classes/CopernicusXmlBuilder.php';

define('COPERNICUS_EXPORT_ISSUES', 0x01);

class CopernicusPlugin extends ImportExportPlugin
{
    /** @inheritdoc */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled()) {
            $this->addLocaleData();
        }
        return $success;
    }

    public function getName() { return 'CopernicusPlugin'; }
    public function getDisplayName() { return __('plugins.importexport.copernicus.displayName'); }
    public function getDescription() { return __('plugins.importexport.copernicus.description'); }
    public function getPluginSettingsPrefix() { return 'copernicus'; }

    /** Entry points: index, issues list, process */
    public function display($args, $request)
    {
        parent::display($args, $request);

        /** @var Journal $context */
        $context = $request->getContext();
        $templateMgr = PKPTemplateManager::getManager($request);
        $templateMgr->assign('plugin', $this);
        $templateMgr->assign('pluginName', $this->getName());

        switch (array_shift($args)) {
            case 'issues':
                return $this->displayIssueList($templateMgr, $context, $request);

            case 'process':
                return $this->processExport($request, $context);

            default:
                $templateMgr->display($this->getTemplateResource('index.tpl'));
        }
    }

    /** Render the list of published issues */
    private function displayIssueList($templateMgr, Journal $journal, $request)
    {
        $issues = Repo::issue()->getCollector()
            ->filterByContextIds([$journal->getId()])
            ->filterByPublished(true)
            ->orderBy('seq', 'ASC')
            ->getMany();

        $templateMgr->assign('plugin', $this);
        $templateMgr->assign('pluginName', $this->getName());
        $templateMgr->assign([
            'issues'  => $issues,
            'journal' => $journal,
        ]);

        $templateMgr->display($this->getTemplateResource('issues.tpl'));
    }

    /** Handle submission from issues.tpl */
    private function processExport($request, Journal $journal)
    {
        $target = $request->getUserVar('target');
        $selectedIds = [];
        $action = '';

        switch ($target) {
            case 'issue':
                $action = 'issues';
                $selectedIds = (array) $request->getUserVar('issueId');
                break;
            default:
                return false;
        }

        if (empty($selectedIds)) {
            $request->redirect(null, null, null, ['plugin', $this->getName(), $action]);
            return false;
        }

        $selectedObjects = [COPERNICUS_EXPORT_ISSUES => $selectedIds];

        // Accept both "export" and "submit"
        if ($request->getUserVar('export') !== null || $request->getUserVar('submit') !== null) {
            return $this->exportJournal($journal, $selectedObjects, $request);
        }

        return false;
    }

    /**
     * Build XML via the builder and stream it to the browser.
     * Reads validateSchema from request (default true).
     */
    private function exportJournal(Journal $journal, array $selectedObjects, $request, $outputFile = null)
    {
        // Resolve selected issues (ensure they belong to this journal)
        $issueIds = isset($selectedObjects[COPERNICUS_EXPORT_ISSUES]) ? $selectedObjects[COPERNICUS_EXPORT_ISSUES] : [];
        $issues = [];
        foreach ($issueIds as $id) {
            $issue = Repo::issue()->get((int)$id);
            if ($issue && (int)$issue->getJournalId() === (int)$journal->getId()) {
                $issues[] = $issue;
            }
        }

        // Read toggle (default = true)
        $validate = $request->getUserVar('validateSchema');
        $validate = ($validate === null) ? true : ((string)$validate === '1' || $validate === 1 || $validate === true);

        // XSD path (try schema/ then plugin root)
        $schemaPath = __DIR__ . '/schema/journal_import_ici.xsd';
        if (!file_exists($schemaPath)) {
            $fallback = __DIR__ . '/journal_import_ici.xsd';
            if (file_exists($fallback)) {
                $schemaPath = $fallback;
            }
        }

        // Build with dedicated builder (validates if $validate = true)
        $builder = new classes\CopernicusXmlBuilder($journal, $issues, [
            'validateSchema' => $validate,
            'schemaPath'     => $schemaPath,
            // If Copernicus UI strictly requires affiliation, you can force fallback text:
            'forceAffiliationFallback' => true,             // <—
            'affiliationFallbackText'  => 'QUEEN ARWA UNIVERSITY',
        ]);

        $doc = $builder->buildDocument(); // DOMDocument

        if (!empty($outputFile)) {
            return $doc->save($outputFile) !== false;
        }

        if (ob_get_length()) { @ob_end_clean(); }
        header('Content-Type: application/xml; charset=UTF-8');
        header('Cache-Control: private');
        header('Content-Disposition: attachment; filename="copernicus-' . (int)$journal->getId() . '.xml"');
        echo $doc->saveXML();
        exit;
    }

    public function executeCLI($scriptName, &$args) { return false; }
    public function usage($scriptName) { return ''; }
}
