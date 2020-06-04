<?php

use CRM_Uepalimport_ExtensionUtil as E;

class CRM_Uepalimport_Form_Import extends CRM_Core_Form {
  private $queue;
  private $queueName = 'uepalimport';

  public function __construct() {
    // create the queue
    $this->queue = CRM_Queue_Service::singleton()->create([
      'type' => 'Sql',
      'name' => $this->queueName,
      'reset' => TRUE, // flush queue upon creation
    ]);

    parent::__construct();
  }

  public function buildQuickForm() {
    $importMenuOptions = [
      'tmp_paroisses' => 'Importer les paroisses',
      'tmp_individus' => 'Importer les individus et leurs relations',
      'tmp_pasteurs_actifs' => 'Importer les pasteurs actifs',
      'tmp_inspections' => 'Importer les inspections',
      'tmp_consistoires' => 'Importer les consistoires',
      'test' => 'Test',
    ];
    $this->addRadio('import', 'Import:', $importMenuOptions, NULL, '<br>');

    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ],
    ]);

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    $values = $this->exportValues();

    if ($values['import'] == 'test') {
      $t = new CRM_Queue_TaskContext();
      //CRM_Uepalimport_Helper::process_tmp_paroisses_task($t, 8);
      //CRM_Uepalimport_Helper::process_tmp_paroisses_task($t, 143);
      //CRM_Uepalimport_Helper::process_tmp_individus_task($t, 'C0545');
      CRM_Uepalimport_Helper::process_tmp_pasteurs_actifs_task($t, 557);
      CRM_Uepalimport_Helper::process_tmp_pasteurs_actifs_task($t, 377);
      //CRM_Uepalimport_Helper::process_tmp_individus_task($t, 'C0881');
    }
    else {
      // put items in the queue
      $sql = "select id from " . $values['import'] . " where id is not null order by id";
      $dao = CRM_Core_DAO::executeQuery($sql);
      while ($dao->fetch()) {
        $method = 'process_' . $values['import'] . '_task';
        $task = new CRM_Queue_Task(['CRM_Uepalimport_Helper', $method], [$dao->id]);
        $this->queue->createItem($task);
      }

      // run the queue
      $runner = new CRM_Queue_Runner([
        'title' => 'UEPAL Import',
        'queue' => $this->queue,
        'errorMode'=> CRM_Queue_Runner::ERROR_CONTINUE,
        'onEnd' => ['CRM_Uepalimport_Helper', 'onEnd'],
        'onEndUrl' => CRM_Utils_System::url('civicrm/uepalimport', 'reset=1'),
      ]);
      $runner->runAllViaWeb();
    }

    // explicit redirect to this same form, rather than the implicit redirect, to make sure the constructor is re-executed
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/uepalimport', 'reset=1'));

    parent::postProcess();
  }

  public function getRenderableElementNames() {
    $elementNames = array();
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

}
