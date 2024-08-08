<?php

declare(strict_types=1);

namespace Tc\Controller;

use App\Controller\DocMasterController;
use App\Utility\QMSFile;
use App\Notification\Simple\SimpleNotification;
use Cake\ORM\Query;
use Cake\Mailer\Mailer;
use App\Notification\Notification;
use Cake\Core\Configure;
use Cake\Log\Log;
use Cake\I18n\FrozenTime;
use Cake\I18n\Time;
use App\Utility\CustomerCache;
use Cake\I18n\FrozenDate;
use Cake\Http\CallbackStream;
use AuditStash\Exception;
use App\Lib\FileLib;
use Svg\Style;
use Cake\Database\Expression\QueryExpression;
use \DateTime;
use App\Controller\PdfController;

//require ROOT.DS.vendor .DS. phpoffice/phpspreadsheet/src/Bootstrap.php;
/**
 * TrainingCompliance Controller
 *
 * @property \Tc\Model\Table\TrainingComplianceTable $TrainingCompliance
 *
 * @method \Tc\Model\Entity\TrainingCompliance[]|\Cake\Datasource\ResultSetInterface paginate($object = null, array $settings = [])
 */
class TrainingComplianceController extends AppController
{
    /**
     * Index method
     *
     * @return \Cake\Http\Response|null
     */
    
    public function initialize(): void
    {
        parent::initialize();
        
        $DateTimeFormat = CustomerCache::read("datetimeformat");
        if (empty($DateTimeFormat) || $DateTimeFormat == null ) {
            $DateTimeFormat = Configure::read('datetimeformat');
        }
        $this->DateTimeFormat = $DateTimeFormat;
        
        $isTransPassword = CustomerCache::read("transactional_password_required");
        if ($isTransPassword == 'Y') {
            $this->transPass  = 1;
        }
        else {
            $this->transPass = 0;
        }
        
    }
    public function index()
    {
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingIndex = $trainingCompliance;
        $session = $this->request->getSession()->read('Auth');
        $customer_id=$session['customer_id'];
        $location_id= $session['base_location_id'];
        $condition = [];
        $conditionn = [];
        $conditiontc = [];
        $this->loadModel('Departments');
        //The drop down on top right.
        $ListOptions = array(
            'view_by_training' => 'View By Training',
            'index' => 'View By Employees',
            'view_by_department' => 'View By Department',
            'view_by_doc' => 'View By Document',
        
        );

        $TrainingMastercondition = [];
        if (!$this->Authorization->can($trainingCompliance, 'index')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
       
       
        $deparments = $this->Departments->find('list', [
            'conditions' => ['customer_id' => $customer_id], 'keyValue' => 'id', 'valueField' => 'department'])->toArray();
        $customerLocationData = $this->request->getQuery('customer_locations_id');
          
          $getListBy= $this->request->getQuery('getlist');
          $departments_id = $this->request->getQuery("departments_id");
          if ( $this->request->getQuery("year") != null &&  $this->request->getQuery("year") != "") {
            $year = $this->request->getQuery("year");
          } else { 
            $year = FrozenDate::now()->format('Y');
          }

          $userIdentity = $this->request->getAttribute('identity');
          $isForTrainingHead = false;
          if ($userIdentity->can('TrainingHead', $trainingIndex)) {
              $isForTrainingHead = true;
          }

          if($year!=null && $year!=""){
            $conditionn["AND"][] = "YEAR(TrainingMaster.due_date)= $year";
            $conditiontc["AND"][] = "YEAR(TrainingCompliance.due_date)= $year";
        };
        $deptCond=[];
        $conditionns = [];
          if ($departments_id != null && trim($departments_id, " ") != "") {
            $deptCond = "id=$departments_id";
            $condition['AND'][] = "Users.departments_id = $departments_id";
            $conditionns['AND'][] = "Users.departments_id = $departments_id";
            $TrainingMastercondition['AND'][] = "JSON_CONTAINS(TrainingMaster.selected_department, '\"$departments_id\"')";
        }
        //default menu link has no index in it so set to index
        if ($getListBy == null || $getListBy == ""){
            $getListBy = "index";
        }
        
        
        
        $TrainingMastercondition = array_merge($TrainingMastercondition,['customer_id' => $session['customer_id'],'TrainingMaster.customer_location_id' => $session['base_location_id']]);
        $condition = array_merge($condition, ['TrainingCompliance.customer_id IN' => [0,$session['customer_id']],
                                'TrainingCompliance.is_for_current_role_or_dept IN'=>[0,1],
                                'Users.base_location_id' => $session['base_location_id'],'Users.active' => 1,'Users.del_status' => 1]);
        $QueryAllData = $this->Authorization->applyScope($this->TrainingCompliance->find('all',['disabledBeforeFind'=>true])->where([$condition])); 
               
        if(isset($getListBy) && $getListBy!='')
        {
            switch ($getListBy) {
                
                case 'view_by_training': 
                    $TrainingComp = $QueryAllData->select([
                        'training_id',
                        'assigned' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                            $QueryAllData->newExpr()->add(['status' => 0]), // Condition for 'all'
                            $QueryAllData->newExpr()->add(['status' => 1]),
                            $QueryAllData->newExpr()->add(['status' => 2]),
                            $QueryAllData->newExpr()->add(['status' => 3])
                        ],
                        ['literal' => 1],
                        ['integer']
                    )),
                        'pending' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                                $QueryAllData->newExpr()->add(['status' => 0]), // Condition for 'unscheduled'
                                //$QueryAllData->newExpr()->add(['status' => 1, 'is_present' =>0]) //condition for absent
                            ],
                            ['literal' => 1],
                            ['integer']
                        )),
                        'inprogress' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                            $QueryAllData->newExpr()->add(['status' => 1]),
                           // $QueryAllData->newExpr()->add(['status' => 1,'is_present !=' =>0]),  ////condition for not absent
                            $QueryAllData->newExpr()->add(['status' => 2]) // Conditions for 'scheduled' 
                            ],
                            ['literal' => 1],
                            ['integer']
                        )),
                        'complete' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                                $QueryAllData->newExpr()->add(['status' => 3]) // Condition for 'complete'
                            ],
                            ['literal' => 1],
                            ['integer']
                        ))
                    ])
                    ->contain(['Users','TrainingMaster' => array(
                        'fields' => ['TrainingMaster.training_name','TrainingMaster.type', 'TrainingMaster.evaluation_type'])])
                    ->group(['training_id'])
                    ->order(['training_id'])->toArray();
                    //debug($TrainingComp);die;
                    $this->loadModel('Tc.TrainingMaster');
                    if ($isForTrainingHead) {
                        $trainMasters = $this->TrainingMaster->find('all',['fields'=>['id', 'training_name','due_date']])
                        ->where([$TrainingMastercondition,$conditionn])
                            ->order(['training_name']);
                    }
                    else {
                        $trainMasters = $this->Authorization->applyScope($this->TrainingMaster->find('all',['fields'=>['id', 'training_name','due_date']])
                            ->where([$TrainingMastercondition,$conditionn])
                            ->order(['training_name']),'ByTraining');
                    }
                   
                    $trainMasters =$trainMasters->toArray();
                    $trainMasters = $this->AddComplianceToTrainingMaster($trainMasters,$TrainingComp);
                    $this->set(compact('trainMasters'));
                    break;
                case 'index': //employee wise report  
                    $TrainingComp = $QueryAllData->select([
                        'user_id',  
                        'assigned' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                            $QueryAllData->newExpr()->add(['status' => 0]), // Condition for 'all'
                            $QueryAllData->newExpr()->add(['status' => 1]),
                            $QueryAllData->newExpr()->add(['status' => 2]),
                            $QueryAllData->newExpr()->add(['status' => 3])
                        ],
                        ['literal' => 1],
                        ['integer']
                    )),
                        'pending' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                                $QueryAllData->newExpr()->add(['status' => 0]), // Condition for 'unscheduled'
                                //$QueryAllData->newExpr()->add(['status' => 1, 'is_present' =>0]) //condition for absent
                            ],
                            ['literal' => 1],
                            ['integer']
                        )),
                        'inprogress' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                            //$QueryAllData->newExpr()->add(['status' => 1,'is_present !=' =>0]),  //condition for not absent
                            $QueryAllData->newExpr()->add(['status' => 1]),
                            $QueryAllData->newExpr()->add(['status' => 2]) // Conditions for 'scheduled'
                            ],
                            ['literal' => 1],
                            ['integer']
                        )),
                        'complete' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                                $QueryAllData->newExpr()->add(['status' => 3]) // Condition for 'complete'
                            ],
                            ['literal' => 1],
                            ['integer']
                        ))
                    ])
                    ->contain(['Users','TrainingMaster'])
                    ->where([$conditionns,$conditiontc])
                    ->group(['user_id'])->toArray();
                    $this->loadModel('Users');
                    if ($isForTrainingHead) {
                        $trainUsers = $this->Users->find('all',['fields'=>['id','emp_id', 'userfullname','departments_id']])
                        ->where([$conditionns,'customer_id' => $session['customer_id'],'base_location_id' => $session['base_location_id'],'active'=>1]);
                    }
                    else {
                        $trainUsers = $this->Authorization->applyScope($this->Users->find('all',['fields'=>['id','emp_id', 'userfullname','departments_id']])
                            ->where(['customer_id' => $session['customer_id'],'base_location_id' => $session['base_location_id'],'active'=>1])
                            ,"ByEmployee");
                    }
                 
                    $trainUsers=  $trainUsers->toArray();
                    $trainUsers = $this->AddComplianceToUsers($trainUsers,$TrainingComp);
                    $this->set(compact('trainUsers'));
                    break;
                case 'view_by_department':
                    $TrainingComp = $QueryAllData->select([
                        'department_id',
                        'assigned' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                            $QueryAllData->newExpr()->add(['status' => 0]), // Condition for 'all'
                            $QueryAllData->newExpr()->add(['status' => 1]),
                            $QueryAllData->newExpr()->add(['status' => 2]),
                            $QueryAllData->newExpr()->add(['status' => 3])
                        ],
                        ['literal' => 1],
                        ['integer']
                    )),
                        'pending' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                                $QueryAllData->newExpr()->add(['status' => 0]), // Condition for 'unscheduled'
                                //$QueryAllData->newExpr()->add(['status' => 1, 'is_present' =>0]) //condition for absent
                            ],
                            ['literal' => 1],
                            ['integer']
                        )),
                        'inprogress' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                                $QueryAllData->newExpr()->add(['status' => 2]), // Conditions for 'scheduled'
                                $QueryAllData->newExpr()->add(['status' => 1])  //condition for not absent
                            ],
                            ['literal' => 1],
                            ['integer']
                        )),
                        'complete' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                                $QueryAllData->newExpr()->add(['status' => 3]) // Condition for 'complete'
                            ],
                            ['literal' => 1],
                            ['integer']
                        ))
                    ])
                    ->contain(['Users','Users.Departments'])
                    ->where([$conditiontc])
                    ->group(['departments_id'])->toArray();
                    if ($isForTrainingHead) {
                        $trainDepts = $this->Departments->find('all', ['conditions' => [$deptCond,'customer_id' => $customer_id]])->toArray();
                    }
                    else {
                        $trainDepts = $this->Authorization->applyScope($this->Departments->find('all', ['conditions' => ['customer_id' => $customer_id,$deptCond]]),"ByDepartment")->toArray();
                    }
                    
                    
                    $trainDepts = $this->AddComplianceToDepartments($trainDepts,$TrainingComp);
                    $this->set(compact('trainDepts'));        
                    break;
                case  'view_by_doc':
                    $conditionn["AND"][] = "DocMaster.id IS NOT NULL";
                    $TrainingCompByDocment = $QueryAllData->select([
                        'TrainingMaster.id',
                        'TrainingMaster.training_for_model_id',
                        'DocMaster.id',
                        'DocMaster.doc_no',
                        'DocMaster.title',
                        'assigned' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                            $QueryAllData->newExpr()->add(['TrainingCompliance.status' => 0]), // Condition for 'all'
                            $QueryAllData->newExpr()->add(['TrainingCompliance.status' => 1]),
                            $QueryAllData->newExpr()->add(['TrainingCompliance.status' => 2]),
                            $QueryAllData->newExpr()->add(['TrainingCompliance.status' => 3])
                        ],
                        ['literal' => 1],
                        ['integer']
                    )),
                        'pending' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                                $QueryAllData->newExpr()->add(['TrainingCompliance.status' => 0]), // Condition for 'unscheduled'
                            ],
                            ['literal' => 1],
                            ['integer']
                        )),
                        'inprogress' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                                $QueryAllData->newExpr()->add(['TrainingCompliance.status' => 2]), // Conditions for 'scheduled'
                                $QueryAllData->newExpr()->add(['TrainingCompliance.status' => 1])  //condition for not absent
                            ],
                            ['literal' => 1],
                            ['integer']
                        )),
                        'complete' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                                $QueryAllData->newExpr()->add(['TrainingCompliance.status' => 3]) // Condition for 'complete'
                            ],
                            ['literal' => 1],
                            ['integer']
                        ))
                    ])
                    ->contain(['Users','TrainingMaster' =>['DocMaster']])
                    ->where([$conditionn,'TrainingMaster.training_for_model_name'=>'DocMaster','TrainingMaster.training_for_model_id IS NOT NULL','TrainingMaster.training_for_model_id !='=>0])
                    ->group(['DocMaster.id'])->toArray();

                    $this->loadModel('Tc.TrainingMaster');
                    $trainMasters =  $this->TrainingMaster->find("all");
                     $trainMasters->select([
                        'id',
                        'training_for_model_id',
                        'count' => $trainMasters->func()->count('*')])
                            ->where(['TrainingMaster.training_for_model_name'=>'DocMaster','TrainingMaster.training_for_model_id IS NOT NULL','TrainingMaster.training_for_model_id !='=>0])
                            ->group(['training_for_model_id']);
                            
                      $trainMastersComp = $trainMasters->toArray();
                    
                      $TrainingCompByDoc = $this->AddComplianceToDoc($trainMastersComp,$TrainingCompByDocment);

                    $this->set(compact('TrainingCompByDoc'));   
                   break;
                }
                $this->set(compact('trainingIndex','getListBy','deparments','ListOptions','year'));
                $this->render($getListBy);
                return; 
        }
    }

    private function AddComplianceToTrainingMaster($trainMasters,$TrainingComp){
        foreach ($trainMasters as $key=>$value){
            foreach ($TrainingComp as $tcomp){
                if ($tcomp->training_id == $value->id) {
                    $trainMasters[$key]['training_compliance'] = $tcomp;
                    break;
                }
            }
        }
        return $trainMasters;
    }

    private function AddComplianceToDepartments($departments,$TrainingComp){
        foreach ($departments as $key=>$value){
            foreach ($TrainingComp as $tcomp){
                if ($tcomp->department_id == $value->id) {
                    $departments[$key]['training_compliance'] = $tcomp;
                    break;
                }
            }
        }
        return $departments;
    }
    private function AddComplianceToUsers($users,$TrainingComp){
        foreach ($users as $key=>$value){
            foreach ($TrainingComp as $tcomp){
                if ($tcomp->user_id == $value->id) {
                    $users[$key]['training_compliance'] = $tcomp;
                    break;
                }
            }
        }
        return $users;
    }

    private function AddComplianceToDoc($trainMasters,$TrainingComp){
        $modifiedTrainMasters = [];
        foreach ($trainMasters as $key=>$value){
            foreach ($TrainingComp as $tcomp){
                if ($tcomp->training_master->training_for_model_id == $value->training_for_model_id ) {
                    $modifiedTrainMasters[$key] = $tcomp;
                    $modifiedTrainMasters[$key]['assigned_training'] = $value['count'];
                    break;
                }
            }
        }
        return $modifiedTrainMasters;
    }


    public function assignTrainingIndex($docId = null, $nextStatusId = null, $prevStatusId = null,$statusId=null)
    {
        $this->loadComponent('WiseWorks');
        $this->loadModel('DocMaster');
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        $docId = ($docId == null) ? null : decryptVal($docId);
        $prevStatusId = ($prevStatusId == null) ? null : decryptVal($prevStatusId);
        $nextStatusId = ($nextStatusId == null) ? null : decryptVal($nextStatusId);
        $statusId =  ($statusId == null) ? null : decryptVal($statusId);
        
        // $statusId = $this->WiseWorks->getNextStep($prevStatusId, $docId); 
        $docMaster = $this->DocMaster->get($docId, [
            'contain' => [
                'DocStatusLog'=>['NextActionByUser'],
                'DocRevisions' => ['DocChildMaster','DocStatusLog' => ['NextActionByUser'], 'sort' => ['id' => 'desc'], 'DocDistributionList'], 'ProcessDocuments', 'DocChildMaster' => ['DocChildDetail']
            ],
        ]);
        $docMaster->previousStepId = $prevStatusId;
        $docMaster->status_id = $statusId;
        $lastLog = $this->getLastLog($docMaster,$prevStatusId); 
        if (!$this->Authorization->can($docMaster, 'assignTrainingIndex')) {
            $this->Flash->error(__("You are not authorized to access this page!!! Only ".$lastLog->username ." | ".$lastLog->userfullname ." can perform this step"));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $access_module = $this->request->getSession()->read('Auth')['customer_role']['rolewise_permissions'];
        $this->loadModel('DocMaster');
        $docMaster = $this->DocMaster->find('all')
            ->contain([
                'DocRevisions' => ["fields" => ["DocRevisions.id", "DocRevisions.doc_master_id"]]
            ])
            ->where(['id' => $docId])->first()->toArray();
        $revId = $docMaster['doc_revisions'][0]['id'];
        $condition = ['status NOT IN' => [5]]; //1:pending,2:in process of approval,3:completeed,4:rejected,5:removed
        $query = $this->request->getQuery('table_search');
        $customerLocationData = $this->request->getQuery('customer_locations_id');
        if ($customerLocationData != "") {
            $condition["AND"][] = ['OR' => ["CustomerLocations.customer_id" => $customerLocationData]];
        }
        if ($query != null && trim($query, " ") != "") {

            $condition['or'][] = "(TrainingMaster.training_name LIKE '%$query%' OR TrainingCompliance.start_date LIKE '%$query%' OR TrainingCompliance.end_date LIKE '%$query%' )";
        }
        //debug($session);

        $condition = array_merge($condition, ['customer_id' => $session['customer_id']]);
        // $condition = array_merge($condition, ['Tc.TrainingCompliance.status NOT IN' => [5]]);


        $this->loadModel('Tc.TrainingBatch');
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('Tc.TrainingCompliance');
        $this->paginate = [
            'contain' => ['TrainingMaster', 'TrainingCompliance'],
            'order' => ['id' => 'desc'],
            'conditions' => [$condition, 'TrainingBatch.training_for_model_name' => 'DocMaster', 'TrainingBatch.training_for_model_id' => $docId]

        ];
        $trainingBatch = $this->paginate($this->TrainingBatch);
        $this->set(compact('trainingBatch', 'query', 'docMaster','nextStatusId','prevStatusId','statusId'));
    }
    
    public function getUserTrainingData($user_id=null)
    {
        $session = $this->request->getSession()->read('Auth');
        $base_location_id = $session['base_location_id'];
        $customer_id = $session['customer_id'];
        $user_id = $user_id;
        
       // total trainings
        $year = $this->request->getQuery("year");
        
        if($year == null || $year == ''){
            $year = date('Y');
        }
        
        $totalAssigned = $this->TrainingCompliance->find('all',['group' => array('TrainingCompliance.training_id'),])->where(['user_id'=>$user_id])->toArray();
        $unscheduletrg = $this->TrainingCompliance->find('all',['contain' => ['TrainingMaster']])->where(['user_id'=>$user_id,'status'=>0,'TrainingMaster.active'  => 1,'YEAR(TrainingCompliance.due_date)' => $year])->toArray();
        $pendings = $this->TrainingCompliance->find('all',['group' => array('TrainingCompliance.training_id'),'contain' => ['TrainingMaster']])->where(['user_id'=>$user_id,'status IS NOT'=>3,'TrainingMaster.active' => 1])->toArray();
          // total mandatory trainings
        $mandatetraining = $this->TrainingCompliance->find('all',['group' => array('TrainingCompliance.training_id'),])->where(['is_mandatory'=>1,'user_id'=>$user_id])->toArray();
        
       
        $remainTrainings = $this->TrainingCompliance->find('all',['group' => array('TrainingCompliance.training_id'),])->where(['is_mandatory'=>1,'status'=>1,'user_id'=>$user_id])->toArray();
        
        // scheduled Trainings
        $AllscheduleTrainings = $this->TrainingCompliance->find('all',['group' => array('TrainingCompliance.training_id'),])->where(['training_batch_id IS NOT'=>null,'user_id'=>$user_id])->toArray();
        $scheduleMandateTraining = $this->TrainingCompliance->find('all',['group' => array('TrainingCompliance.training_id'),])->where(['is_mandatory'=>1,'training_batch_id IS NOT'=>null,'user_id'=>$user_id])->toArray();
        $otherScheduletrainings = count($AllscheduleTrainings) - count($scheduleMandateTraining);
        
        // Un heduled Trainings
        $unscheduleTrainings = $this->TrainingCompliance->find('all',['group' => array('TrainingCompliance.training_id'),])->where(['training_batch_id IS'=>null,'user_id'=>$user_id])->toArray();
        $unscheduleMandateTraining = $this->TrainingCompliance->find('all',['group' => array('TrainingCompliance.training_id'),])->where(['is_mandatory'=>1,'training_batch_id IS'=>null,'user_id'=>$user_id])->toArray();
        $otherUnScheduletrainings = count($unscheduleTrainings) - count($unscheduleMandateTraining);
      
        //Complete Trainings
      // $completedtraining = $this->TrainingCompliance->find('all',['group' => ['TrainingCompliance.training_id'],'contain' => ['TrainingMaster'],'disabledBeforeFind'=>true])->where(['status'=>3,'user_id'=>$user_id,'TrainingMaster.id IS NOT NULL'])->toArray();
        
        $conditionData = [
            'user_id'=>$user_id,'TrainingCompliance.status'=>3,'YEAR(TrainingCompliance.due_date)' => $year
        ];
        $compTrgCount = $this->TrainingCompliance->find('all', ['conditions' => $conditionData,'disabledBeforeFind'=>true])->count();
       
        $failedtrg = $this->TrainingCompliance->find('all',['contain' => ['TrainingMaster']])->where(['user_id'=>$user_id,'TrainingCompliance.status'=>4,'TrainingMaster.type'=>2])->toArray();
        $failTrgCount = count($failedtrg);
       
        
        $mandateCompletedtraining = $this->TrainingCompliance->find('all',['group' => array('TrainingCompliance.training_id'),])->where(['is_mandatory'=>1,'status'=>3,'user_id'=>$user_id])->toArray();
        //$otherCompletedtraining = count($completedtraining) - count($mandateCompletedtraining);
        //$selfTraininigs = $this->TrainingMaster->UserSelfTraining->find('all',['contain'=>['TrainingMaster','TrainingViewLog','Users'=>['Departments']]])->where(["UserSelfTraining.customer_id" => $customer_id,"UserSelfTraining.customer_location_id" => $base_location_id,"UserSelfTraining.user_id" => $user_id,"UserSelfTraining.is_approve !=" => 1])->toArray();
        
        $this->set(compact('compTrgCount','session','user_id','mandatetraining','remainTrainings','unscheduleTrainings','totalAssigned','scheduleMandateTraining','mandateCompletedtraining','AllscheduleTrainings','otherScheduletrainings','unscheduleMandateTraining','otherUnScheduletrainings','pendings','unscheduletrg','failTrgCount'));
        
    }
    
    
    public function viewMySessionData($user_id=null,$training_compliance_id=null,$training_master_id=null ,$isfailed = null)
    {
        
        $user_id = ($user_id == null) ? null : decryptVal($user_id);
        $training_compliance_id = ($training_compliance_id == null) ? null : decryptVal($training_compliance_id);
        $training_master_id = ($training_master_id == null) ? null : decryptVal($training_master_id);
        $isfailed = ($isfailed == null) ? null : decryptVal($isfailed);
        $session = $this->request->getSession()->read('Auth');
        $plugin=$this->request->getParam('plugin');
        $method=$this->request->getParam('action');
        $controller=$this->request->getParam('controller');
        
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        $session=$this->request->getSession()->read("Auth");
        $customer_id=$session['customer_id'];
        $location_id=$session['base_location_id'];
        $this->loadModel('Tc.TrainingCompliance');
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('Tc.TrainingSessionSlots');
        
        $failed=[];
        if($isfailed== 4){
            $failed = ['TrainingCompliance.status'=>4];
        }
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        if (!$this->Authorization->can($trainingCompliance, 'index')) {
            $this->Flash->error(__('You are not authorized to access this page!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        $trainingMaster = $this->TrainingMaster->find('all',[
            'fields' => ['training_name','passing_score','out_of_marks']])->where(['id'=>$training_master_id,])->toArray();
                
            $trainingCompliance = $this->TrainingCompliance->find('all',[
                'contain' => ['TrainingSlotAttendance'=>["TrainingSessionSlots"=>['TrainingSlotAttendance'=>['TrainingBatch']],'TrainingBatch'=>['Instructor']],'TrainingMaster','TrainingBatch'=>['TrainingSessionSlots'=>['TrainingSlotAttendance'],'Instructor'],'TrainingTestResult' => function ($q) {
                    return $q->select([
                        "training_complaince_id"  => "TrainingTestResult.training_complaince_id",
                        "total_quetions"          => new QueryExpression("COUNT(TrainingTestResult.question_bank_master_id)"),
                        "correct_answer"          => new QueryExpression("(SUM(CASE WHEN  TrainingTestResult.question_bank_mcq_options_id = QuestionBankMaster.question_bank_mcq_options_id THEN 1 ELSE 0 END ))"),
                        "attempt"                 => "TrainingTestResult.attempt",
                    ])
                    ->innerJoin(["QuestionBankMaster"=>"question_bank_master"],["QuestionBankMaster.id = TrainingTestResult.question_bank_master_id"])
                    ->group(["attempt","training_complaince_id"]);
                }
                ]])->where(['TrainingCompliance.training_id'=>$training_master_id,'TrainingCompliance.user_id'=>$user_id,$failed])->order('TrainingCompliance.id','ASC')->toArray();
               // debug($trainingCompliance);die;
        $this->set(compact('user_id','trainingCompliance','trainingMaster'));   
        
        // ,'TrainingSlotAttendance'=>['TrainingBatch',
        // 'Users' => [
        //     'fields' => ["userfullname", "emp_id"],
        // ]]
    }


    public function usertrainingindex($user_id=null)
    {
        $user_id = ($user_id == null) ? null : decryptVal($user_id);
        
        $this->loadModel('DocMaster');
        $this->loadModel('Tc.TrainingMaster');
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingIndex = $trainingCompliance;
        $session = $this->request->getSession()->read('Auth');
        $base_location_id = $session['base_location_id'];
        $customer_id = $session['customer_id'];
        if (!$this->Authorization->can($trainingCompliance, 'usertrainingindex')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        if(isset($user_id) && $user_id!='')
        {
            $user_id = $user_id;
        }
        else {
            $user_id = $session->id;
            
        }
       
        
        
        $query=$this->request->getQuery("section_name");
        if($query!=null && trim($query," ")!=""){
            $condition["or"][]="TrainingMaster.training_name like '%$query%'";
        }
        $year = $this->request->getQuery("year");
        //to find count of shedule trg.
        
        if($year == null || $year == ''){
            $year = date('Y');
        }
        
//         if($year!=null && $year!=""){
//             $conditionsss["AND"][] = "YEAR(TrainingCompliance.due_date)= $year";
//         }
        $condition = [
            'user_id'=>$user_id,'TrainingCompliance.status >='=>1,'TrainingCompliance.status <'=>3,"YEAR(TrainingCompliance.due_date)= $year",
            'TrainingMaster.step_completed'=>1,"TrainingMaster.training_name like '%$query%'"
        ];
        $my_training_new_entry1 = CustomerCache::read("my_training_new_entry");
        $trainingCompliance1 = $this->TrainingCompliance->find('all',[
            'contain' => ['TrainingMaster'],
            'conditions'=>$condition,
            'order'=>['TrainingCompliance.training_id'=>'DESC']
        ])->toArray();
        $shetrgCount = 0;
        foreach ($trainingCompliance1 as $pendingTraining1){
            if($pendingTraining1->training_master->type == 2 && $pendingTraining1->is_present == '0' && $my_training_new_entry1 == 'Y'){
                continue;
            }
            $shetrgCount++;
        }
        
        
        $this->paginate = [
            'contain' => ['TrainingMaster'=>['TrainingSections'=>['SectionMaster']],'TrainingBatch','TrainingViewLog'],
            'conditions'=>$condition,
//             'sortWhitelist' => [
//                 'TrainingMaster.due_date'
//             ],
            'order'=>['TrainingCompliance.training_id'=>'DESC']
        ];
        //$this->paginate['order'] = ['TrainingMaster.due_date' => 'ASC'];
        $trainingCompliance = $this->paginate($this->TrainingCompliance);
        //debug($trainingCompliance);die;
        foreach ($trainingCompliance as $key=>$tc):
        $agingQuery =  $this->TrainingCompliance->find()->where(['id'=>$tc->id]);
        $aging = $agingQuery->select(['aging' => $agingQuery->func()->dateDiff([
            'NOW()' => 'literal',
            'TrainingCompliance.created' => 'identifier'
        ])])->toArray();
        $tc->aging = $aging;
        $docmasterName = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_name:'';
        $docmasterId = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_id:'';
       
        if (isset($docmasterId) && $docmasterName == 'DocMaster' || $docmasterName == 'Doc') {
            $docMaster_id = $tc->training_master->training_for_model_id; 
            
            $docMaster = $this->DocMaster->find('all',["fields" => ["current_revision_no",'doc_no']])->where(['DocMaster.id' => $docMaster_id])->first();
            if($docMaster != null)
            {
                
            $docData = array('docmaster_id'=>$docMaster_id, 'current_revision_no'=> $docMaster->current_revision_no,'doc_no'=>$docMaster->doc_no);
            $tc->training_master->docmaster = $docData;
            }
             }
            else {
                continue;
            }
        endforeach;
        
        $this->loadModel('TrainingTypeSubtypeMaster');
        $type = $this->TrainingTypeSubtypeMaster->find('list', ['keyField' => 'id','valueField' => 'name'])->where(["type"=>'Training'])->toArray();
        
        $this->loadModel('UserSelfTraining');
        $PendingTrainings = $this->TrainingMaster->UserSelfTraining->find('all',['contain'=>['TrainingMaster','TrainingViewLog','Users'=>['Departments']]])->where(["UserSelfTraining.customer_id" => $customer_id,"UserSelfTraining.customer_location_id" => $base_location_id,"UserSelfTraining.user_id" => $user_id,"UserSelfTraining.is_approve !=" => 1])->toArray();
        
        foreach ($PendingTrainings as $key=>$tc):
        $docmasterName = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_name:'';
        $docmasterId = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_id:'';
        
        if (isset($docmasterId) && $docmasterName == 'DocMaster' || $docmasterName == 'Doc') {
            $docMaster_id = $tc->training_master->training_for_model_id;
            $docMaster = $this->DocMaster->find('all',["fields" => ["current_revision_no"]])->where(['DocMaster.id' => $docMaster_id])->first()->toArray();
            $docData = array('docmaster_id'=>$docMaster_id, 'current_revision_no'=> $docMaster['current_revision_no']);
            $tc->training_master->docmaster = $docData;
        }
        else {
            continue;
        }
        endforeach;
      
        $this->getUserTrainingData($user_id);
        $this->loadModel('Tc.TrainingMaster');
        $TrainingMastersuspended=$this->TrainingMaster->find('all', ['fields' => 'id'])->where(["is_suspended"=>'1'])->toArray();
        $newTrainingMastersuspendedIds = array_column($TrainingMastersuspended, 'id');
        
        $this->set(compact('year','shetrgCount','newTrainingMastersuspendedIds','TrainingMastersuspended','user_id','trainingCompliance','trainingIndex','PendingTrainings','type'));     
       $this->set('dateTimeFormat',$this->DateTimeFormat);
    }
    
    
    public function userunsheduletraining($user_id=null)
    {
        $user_id = ($user_id == null) ? null : decryptVal($user_id);
        
        $this->loadModel('DocMaster');
        $this->loadModel('Tc.TrainingMaster');
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingIndex = $trainingCompliance;
        $session = $this->request->getSession()->read('Auth');
        $base_location_id = $session['base_location_id'];
        $customer_id = $session['customer_id'];
        if (!$this->Authorization->can($trainingCompliance, 'usertrainingindex')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        if(isset($user_id) && $user_id!='')
        {
            $user_id = $user_id;
        }
        else {
            $user_id = $session->id;
            
        }
        $condition = [];
        $condition = [
            'user_id'=>$user_id,'TrainingCompliance.status '=>0,'TrainingMaster.step_completed'=>1,'TrainingMaster.active'=>1
        ];
        
        
        $query=$this->request->getQuery("section_name");
        if($query!=null && trim($query," ")!=""){
            $condition["AND"][]="TrainingMaster.training_name like '%$query%'";
        }
        $year = $this->request->getQuery("year");
        
        if($year == null || $year == ''){
            $year = date('Y');
        }
        
                if($year!=null && $year!=""){
                    $condition["AND"][] = "YEAR(TrainingCompliance.due_date)= $year";
                }
        $this->paginate = [
            'contain' => ['TrainingMaster','TrainingBatch','TrainingViewLog'],
            'conditions'=>$condition,
            'order'=>['TrainingCompliance.training_id'=>'DESC']
        ];
  
        $trainingCompliance = $this->paginate($this->TrainingCompliance);
        
        foreach ($trainingCompliance as $key=>$tc):
        $agingQuery =  $this->TrainingCompliance->find()->where(['id'=>$tc->id]);
        $aging = $agingQuery->select(['aging' => $agingQuery->func()->dateDiff([
            'NOW()' => 'literal',
            'TrainingCompliance.created' => 'identifier'
        ])])->toArray();
        $tc->aging = $aging;
        $docmasterName = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_name:'';
        $docmasterId = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_id:'';
        
        if (isset($docmasterId) && $docmasterName == 'DocMaster' || $docmasterName == 'Doc') {
            $docMaster_id = $tc->training_master->training_for_model_id;
            
            $docMaster = $this->DocMaster->find('all',["fields" => ["current_revision_no",'doc_no']])->where(['DocMaster.id' => $docMaster_id])->first();
            if($docMaster != null)
            {
                
                $docData = array('docmaster_id'=>$docMaster_id, 'current_revision_no'=> $docMaster->current_revision_no,'doc_no'=>$docMaster->doc_no);
                $tc->training_master->docmaster = $docData;
            }
        }
        else {
            continue;
        }
        endforeach;
        
        $this->loadModel('TrainingTypeSubtypeMaster');
        $type = $this->TrainingTypeSubtypeMaster->find('list', ['keyField' => 'id','valueField' => 'name'])->where(["type"=>'Training'])->toArray();
        
        $this->loadModel('UserSelfTraining');
        $PendingTrainings = $this->TrainingMaster->UserSelfTraining->find('all',['contain'=>['TrainingMaster','TrainingViewLog','Users'=>['Departments']]])->where(["UserSelfTraining.customer_id" => $customer_id,"UserSelfTraining.customer_location_id" => $base_location_id,"UserSelfTraining.user_id" => $user_id,"UserSelfTraining.is_approve !=" => 1])->toArray();
        
        foreach ($PendingTrainings as $key=>$tc):
        $docmasterName = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_name:'';
        $docmasterId = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_id:'';
        
        if (isset($docmasterId) && $docmasterName == 'DocMaster' || $docmasterName == 'Doc') {
            $docMaster_id = $tc->training_master->training_for_model_id;
            $docMaster = $this->DocMaster->find('all',["fields" => ["current_revision_no"]])->where(['DocMaster.id' => $docMaster_id])->first()->toArray();
            $docData = array('docmaster_id'=>$docMaster_id, 'current_revision_no'=> $docMaster['current_revision_no']);
            $tc->training_master->docmaster = $docData;
        }
        else {
            continue;
        }
        endforeach;
        
        $this->getUserTrainingData($user_id);
        $this->set(compact('year','user_id','trainingCompliance','trainingIndex','PendingTrainings','type'));
        $this->set('dateTimeFormat',$this->DateTimeFormat);
    }
    
    public function userScheduleTrainings($user_id=null)
    {
        $user_id = ($user_id == null) ? null : decryptVal($user_id);
        
        $this->loadModel('DocMaster');
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingIndex = $trainingCompliance;
        $session = $this->request->getSession()->read('Auth');
        $base_location_id = $session['base_location_id'];
        $customer_id = $session['customer_id'];
        if (!$this->Authorization->can($trainingCompliance, 'usertrainingindex')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        if(isset($user_id) && $user_id!='')
        {
            $user_id = $user_id;
        }
        else {
            $user_id = $session->id;
            
        }
        $condition = [
            'user_id'=>$user_id,'TrainingCompliance.status <'=>3,'TrainingCompliance.is_mandatory IS'=>NULL
        ];
        
        $this->paginate = [
            'contain' => ['TrainingMaster','TrainingViewLog'],
            'conditions'=>$condition,
            'sortWhitelist' => [
                'TrainingMaster.due_date'
            ]
            //             'order'=>['TrainingMaster.due_date'=>'Desc',]
        ];
        $this->paginate['order'] = ['TrainingMaster.due_date' => 'ASC'];
        $trainingCompliance = $this->paginate($this->TrainingCompliance);
        foreach ($trainingCompliance as $key=>$tc):
        $docmasterName = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_name:'';
        $docmasterId = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_id:'';
        
        if (isset($docmasterId) && $docmasterName == 'DocMaster' || $docmasterName == 'Doc') {
            $docMaster_id = $tc->training_master->training_for_model_id;
            $docMaster = $this->DocMaster->find('all',["fields" => ["current_revision_no"]])->where(['DocMaster.id' => $docMaster_id])->first()->toArray();
            $docData = array('docmaster_id'=>$docMaster_id, 'current_revision_no'=> $docMaster['current_revision_no']);
            $tc->training_master->docmaster = $docData;
        }
        else {
            continue;
        }
        endforeach;
        
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('UserSelfTraining');
        $PendingTrainings = $this->TrainingMaster->UserSelfTraining->find('all',['contain'=>['TrainingMaster','TrainingViewLog','Users'=>['Departments']]])->where(["UserSelfTraining.customer_id" => $customer_id,"UserSelfTraining.customer_location_id" => $base_location_id,"UserSelfTraining.user_id" => $user_id,"UserSelfTraining.is_approve !=" => 1])->toArray();
        
        foreach ($PendingTrainings as $key=>$tc):
        $docmasterName = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_name:'';
        $docmasterId = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_id:'';
        
        if (isset($docmasterId) && $docmasterName == 'DocMaster' || $docmasterName == 'Doc') {
            $docMaster_id = $tc->training_master->training_for_model_id;
            $docMaster = $this->DocMaster->find('all',["fields" => ["current_revision_no"]])->where(['DocMaster.id' => $docMaster_id])->first()->toArray();
            $docData = array('docmaster_id'=>$docMaster_id, 'current_revision_no'=> $docMaster['current_revision_no']);
            $tc->training_master->docmaster = $docData;
        }
        else {
            continue;
        }
        endforeach;
        $this->getUserTrainingData($user_id);
        $this->set(compact('user_id','trainingCompliance','trainingIndex','PendingTrainings',));
        $this->set('dateTimeFormat',$this->DateTimeFormat);
    }
    

    public function userUnScheduleTrainings($user_id=null)
    {
        $user_id = ($user_id == null) ? null : decryptVal($user_id);
        
        $this->loadModel('DocMaster');
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingIndex = $trainingCompliance;
        $session = $this->request->getSession()->read('Auth');
        $base_location_id = $session['base_location_id'];
        $customer_id = $session['customer_id'];
        if (!$this->Authorization->can($trainingCompliance, 'usertrainingindex')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        if(isset($user_id) && $user_id!='')
        {
            $user_id = $user_id;
        }
        else {
            $user_id = $session->id;
            
        }
        $condition = [
            'user_id'=>$user_id,'TrainingCompliance.status <'=>3,'TrainingCompliance.training_batch_id IS'=>NULl,
        ];
        
        $this->paginate = [
            'contain' => ['TrainingMaster','TrainingViewLog'],
            'conditions'=>$condition,
            'sortWhitelist' => [
                'TrainingMaster.due_date'
            ]
            //             'order'=>['TrainingMaster.due_date'=>'Desc',]
        ];
        $this->paginate['order'] = ['TrainingMaster.due_date' => 'ASC'];
        $trainingCompliance = $this->paginate($this->TrainingCompliance);
        foreach ($trainingCompliance as $key=>$tc):
        $docmasterName = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_name:'';
        $docmasterId = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_id:'';
        
        if (isset($docmasterId) && $docmasterName == 'DocMaster' || $docmasterName == 'Doc') {
            $docMaster_id = $tc->training_master->training_for_model_id;
            $docMaster = $this->DocMaster->find('all',["fields" => ["current_revision_no"]])->where(['DocMaster.id' => $docMaster_id])->first()->toArray();
            $docData = array('docmaster_id'=>$docMaster_id, 'current_revision_no'=> $docMaster['current_revision_no']);
            $tc->training_master->docmaster = $docData;
        }
        else {
            continue;
        }
        endforeach;
        
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('UserSelfTraining');
        $PendingTrainings = $this->TrainingMaster->UserSelfTraining->find('all',['contain'=>['TrainingMaster','TrainingViewLog','Users'=>['Departments']]])->where(["UserSelfTraining.customer_id" => $customer_id,"UserSelfTraining.customer_location_id" => $base_location_id,"UserSelfTraining.user_id" => $user_id,"UserSelfTraining.is_approve !=" => 1])->toArray();
        
        foreach ($PendingTrainings as $key=>$tc):
        $docmasterName = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_name:'';
        $docmasterId = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_id:'';
        
        if (isset($docmasterId) && $docmasterName == 'DocMaster' || $docmasterName == 'Doc') {
            $docMaster_id = $tc->training_master->training_for_model_id;
            $docMaster = $this->DocMaster->find('all',["fields" => ["current_revision_no"]])->where(['DocMaster.id' => $docMaster_id])->first()->toArray();
            $docData = array('docmaster_id'=>$docMaster_id, 'current_revision_no'=> $docMaster['current_revision_no']);
            $tc->training_master->docmaster = $docData;
        }
        else {
            continue;
        }
        endforeach;
        $this->getUserTrainingData($user_id);
        $this->set(compact('user_id','trainingCompliance','trainingIndex','PendingTrainings',));
        $this->set('dateTimeFormat',$this->DateTimeFormat);
    }
    
    public function mycompletedTrainings()
    {
         $this->loadModel('DocMaster');
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingIndex = $trainingCompliance;
        // $year = $this->request->getQuery("year");
        $session = $this->request->getSession()->read('Auth');
        $base_location_id = $session['base_location_id'];
        $customer_id = $session['customer_id'];
        if (!$this->Authorization->can($trainingCompliance, 'usertrainingindex')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
         $user_id = $session->id;
            
         $year = $this->request->getQuery("year");
         //to find count of shedule trg.
         
         if($year == null || $year == ''){
             $year = date('Y');
         }
        $condition = [
            'user_id'=>$user_id,'TrainingCompliance.status'=>3,'TrainingMaster.id IS NOT NULL', "YEAR(TrainingCompliance.due_date) >= YEAR(CURDATE())","YEAR(TrainingCompliance.completed_date)= $year",

        ];
        $query=$this->request->getQuery("section_name");
        if($query!=null && trim($query," ")!=""){
            $condition["or"][]="TrainingMaster.training_name like '%$query%'";
        }


        // if($year!=null && $year!=""){
        //                 $conditionsss["AND"][] = "YEAR(TrainingCompliance.completed_date)= $year";
        //              }
        // $condition = [
        //    "YEAR(TrainingCompliance.completed_date)= $year",
            
        // ];
        // debug($condition);
        $this->paginate = [
            'contain' => ['TrainingMaster','TrainingBatch', "TrainingTestResult"],
            'conditions'=>$condition,
            'order'=>['id'=>'Desc'],
            'disabledBeforeFind'=>true
        ];
        $trainingCompliance = $this->paginate($this->TrainingCompliance);
        

        foreach ($trainingCompliance as $key=>$tc):
        $docmasterName = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_name:'';
        $docmasterId = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_id:'';
        
        if (isset($docmasterId) && $docmasterName == 'DocMaster') {
            $docMaster_id = $tc->training_master->training_for_model_id;
            $docMaster = $this->DocMaster->find('all',["fields" => ["current_revision_no",'doc_no']])->where(['DocMaster.id' => $docMaster_id])->first();
            if ($docMaster) {
                $docMaster = $docMaster->toArray();
                $docData = array('docmaster_id'=>$docMaster_id, 'current_revision_no'=> $docMaster['current_revision_no'],'doc_no'=>$docMaster['doc_no']);
                $tc->training_master->docmaster = $docData;
            }
           
        }
        else {
            continue;
        }
        endforeach;
        $this->getUserTrainingData($user_id);
        $this->loadModel('Tc.TrainingMaster');
        
        $this->loadModel('TrainingTypeSubtypeMaster');
        $type = $this->TrainingTypeSubtypeMaster->find('list', ['keyField' => 'id','valueField' => 'name'])->where(["type"=>'Training'])->toArray();
        
        $this->set(compact('user_id','trainingCompliance','trainingIndex','type','year'));
        $this->set('dateTimeFormat',$this->DateTimeFormat);
    }
    

    public function showUserTraining($user_id=null,$flag=null)
    {
        $user_id = ($user_id == null) ? null : decryptVal($user_id);
        $flag = decryptVal($flag);
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingIndex = $trainingCompliance;
        $session = $this->request->getSession()->read('Auth');
        $base_location_id = $session['base_location_id'];
        $customer_id = $session['customer_id'];
        $query = $this->request->getQuery("search");
        if (!$this->Authorization->can($trainingCompliance, 'usertrainingindex')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $this->loadModel('Users');
        $usrdetail = $this->Users->find('all',['fields'=>['id','deactivate_date']])->where(["Users.id" => $user_id])->toArray();
        $conditionss = [];
        if($usrdetail[0]['fdeactivate_date'] != ''){
            $conditionss = ['TrainingCompliance.status IN'=>[2,3,4]];
        }else{
            $conditionss = ['TrainingCompliance.status IN'=>[0,1,2,3,4]];
        }
        $condition = [
            'user_id'=>$user_id,
           // 'TrainingCompliance.status IN'=>[0,1,2,3,4],
            //'TrainingCompliance.is_for_current_role_or_dept'=> 1,
            //'TrainingMaster.active'=>1,
        ];
        
        if ($query != null && trim($query, " ") != "") {
            $query = preg_replace("/[\s]/", "%", $query);
            $condition['or'][] = "TrainingMaster.training_name like '%$query%'";
            //$condition['or'][] = "TrainingCompliance.fdue_date like '%$query%'";
        }
        
        $years = $this->request->getQuery("year");
        if($years == null || $years == ''){
            $years = date('Y');
        }
        
        if($years!=null && $years!=""){
            $condition["AND"][] = "YEAR(TrainingMaster.due_date)= $years";
        }
        $statusoption = $this->request->getQuery("status");
        // debug($statusoption);die;
         if($statusoption == '0'){
             $statusoption = [0,1];
         }
        $statuscondtion = [];
        if($statusoption != null || $statusoption !=''){
            $statuscondtion = ['TrainingCompliance.status IN' => $statusoption];
        }
        $this->paginate = [
            'contain' => ['TrainingMaster','TrainingBatch'],
            'conditions'=>[$condition,$conditionss,$statuscondtion],
            'disabledBeforeFind'=>true
        ];
        $trainingCompliance = $this->paginate($this->TrainingCompliance);//debug($trainingCompliance);
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('UserSelfTraining');
        
        $userdata = $this->TrainingCompliance->Users->find('all',['contain'=>['Departments',]])->where(["Users.id" => $user_id])->toArray();
        if (isset($userdata)) {
            $userdata=$userdata[0];
        }
        
        $this->loadComponent('Common');
        $type = $this->Common->getTrainingTypeSubtypeMaster();
        $PendingTrainings = $this->TrainingMaster->UserSelfTraining->find('all',['contain'=>['TrainingMaster','Users'=>['Departments']]])->where(["UserSelfTraining.customer_id" => $customer_id,"UserSelfTraining.customer_location_id" => $base_location_id,"UserSelfTraining.user_id" => $user_id])->toArray();
        
        
        
        $this->set(compact('years','flag','user_id','userdata','trainingCompliance','trainingIndex','PendingTrainings','type'/*,'alltrainingCompliance'*/));
        
        if ($this->request->getData('export') != null) {
            $alltrainingCompliance = $this->TrainingCompliance->find('all', [
                'contain' => ['TrainingMaster', 'TrainingBatch'],
                'conditions'=>[$condition,$conditionss,$statuscondtion],
                'disabledBeforeFind'=>true
            ])->toArray();
            
            $conn = $this;
            $CallBackStream = new CallbackStream(function () use ($conn,$alltrainingCompliance) {
                try {
                    $conn->viewBuilder()->setLayout("xls");
                    $conn->set(compact('alltrainingCompliance'));
                    echo $conn->render('show_user_training_export');
                } catch (Exception $e) {
                    echo $e->getMessage();
                    $e->getTrace();
                }
            });
                return $this->response->withBody($CallBackStream)
                ->withAddedHeader("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
                ->withAddedHeader("Content-disposition", "attachment; filename=User Trainings List.xls");
                
        }

        if ($this->request->getData('exportpdf') != null) {
            $alltrainingCompliance = $this->TrainingCompliance->find('all', [
                'contain' => ['TrainingMaster', 'TrainingBatch'],
                'conditions'=>[$condition,$conditionss,$statuscondtion],
                'disabledBeforeFind'=>true
            ])->toArray();

            $pdfCon = new PdfController();
            $this->viewBuilder()->setLayout("blank");
            $this->set(compact('alltrainingCompliance'));
            $html = $this->render('show_user_training_export');
            $pdfCon->exportToPDF($html, 'Training Details - By Employee');
        }
    }
    
    public function showUserEmployee($departments_id=null)
    {
        $session = $this->request->getSession()->read('Auth');
        $departments_id = ($departments_id == null) ? null : decryptVal($departments_id);
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        if (!$this->Authorization->can($trainingCompliance, 'usertrainingindex')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('UserSelfTraining');
        // $userdata = $this->TrainingCompliance->Users->find('all',['contain'=>['Departments']])->toArray();
        $condition = array();
         $condition = array_merge($condition, ['TrainingCompliance.customer_id' => $session['customer_id'],'Users.base_location_id' => $session['base_location_id'],'Users.active' => 1,'Users.del_status' => 1]);
          $condition['and'][] = "TrainingCompliance.department_id = $departments_id";
           
         $Query = $this->Authorization->applyScope($this->TrainingCompliance->find('all')->where([$condition]));
         $trainingCompliance = '';
         $AllUsers = $Query->select([
            'user_id','Users.userfullname','Departments.department',
            'count' => $Query->func()->count('*'),
        ])
        ->contain(['Users'=>['Departments']])
        ->group('user_id')->toArray();
        
        $completedUsers = $Query->select([
            'user_id','Users.userfullname','Departments.department',
        ])
        ->group('user_id')->where(['TrainingCompliance.status'=>3])->toArray();


        $completedUsers = $Query->select([
            'user_id','Users.userfullname','Departments.department',
        ])
        ->group('user_id')->where(['TrainingCompliance.status'=>3])->toArray();
        
        
        $QueryPending = $this->Authorization->applyScope($this->TrainingCompliance->find('all'));
        $pendingUsers = $QueryPending->select([
            'user_id',
            'count' => $QueryPending->func()->count('*'),
        ])
        ->contain(['TrainingMaster'])
        //->group('user_id')->where(['TrainingMaster.type'=>'Classroom','TrainingCompliance.training_batch_id IS'=>NULL])->toArray();
        ->group('user_id')->where(['TrainingCompliance.status !='=>3])->toArray();
        
        foreach ($AllUsers as $keyuser => $user)
        {
           
           $userid=$user->user_id;
           $commpleteCount = 0;
           $pendingCount = 0;
            foreach ($completedUsers as $usercomplete)
            {                
                if ($userid == $usercomplete->user_id)
                {
                    $commpleteCount = $usercomplete->count;
                    
                }     
            }
            
            foreach ($pendingUsers as $pending)
            {
                //debug($pending);
                if ($userid == $pending->user_id)
                {
                    $pendingCount = $pending->count;
                    //$commpleteCount = $commpleteCount - $pending->count;
                    break;
                }
                
                
            }
            
            if (isset($pendingCount) && $pendingCount > 0) {
                $AllUsers[$keyuser]->pending = $pendingCount;
            }
                        
            
            if (isset($commpleteCount) && $commpleteCount > 0) {
                $AllUsers[$keyuser]->completed = $commpleteCount;
                $AllUsers[$keyuser]->inCompleted = ($user->count - $commpleteCount)-$pendingCount;
                $TrainAvg  = round($commpleteCount / $user->count * 100);
                $AllUsers[$keyuser]->averageTrainingComp=$TrainAvg;
            }elseif ($commpleteCount == 0)
            {
                $AllUsers[$keyuser]->completed = $commpleteCount;
                $AllUsers[$keyuser]->inCompleted = $user->count - $pendingCount;
                $TrainAvg  = round($commpleteCount / $user->count * 100);
                $AllUsers[$keyuser]->averageTrainingComp=$TrainAvg;
            }
            else {
                $AllUsers[$keyuser]->completed = 0;
                $AllUsers[$keyuser]->inCompleted = 0;
                $TrainAvg  = 0;
                $AllUsers[$keyuser]->averageTrainingComp=$TrainAvg;
            }
        }

        // debug($AllUsers);
        $this->set(compact('AllUsers','trainingCompliance','pending'));
        if ($this->request->getData('export') != null) {
            
            $conn = $this;
            $CallBackStream = new CallbackStream(function () use ($conn,$AllUsers) {
                try {
                    $conn->viewBuilder()->setLayout("xls");
                    $conn->set(compact('AllUsers'));
                    echo $conn->render('show_user_employee_export');
                } catch (Exception $e) {
                    echo $e->getMessage();
                    $e->getTrace();
                }
            });
                return $this->response->withBody($CallBackStream)
                ->withAddedHeader("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
                ->withAddedHeader("Content-disposition", "attachment; filename=Departmentwise users.xls");
                
        }

        if ($this->request->getData('exportpdf') != null) {
            $pdfCon = new PdfController();
            $this->viewBuilder()->setLayout("blank");
            $this->set(compact('AllUsers'));
            $html = $this->render('show_user_employee_export');
            $pdfCon->exportToPDF($html, ' Training Details - By Department');                
        }
        
        
    }
        

    
    public function showTrainingDetails($training_id=null)
    {
        $training_id = ($training_id == null) ? null : decryptVal($training_id);
        
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingIndex = $trainingCompliance;
        $session = $this->request->getSession()->read('Auth');
        
        $location_id= $session['base_location_id'];
        $customer_id = $session['customer_id'];
        if (!$this->Authorization->can($trainingCompliance, 'usertrainingindex')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        $this->loadModel('Tc.TrainingMaster');
        $trainingMaster = $this->TrainingCompliance->TrainingMaster->get($training_id, [
            'contain' => ['Customer','TrainingCompliance'=>['Users'=>['Departments']],'TrainingBatch','TrainingSections',],
        ]);
        //debug($trainingMaster);die;
        $selectedUsers = array();
        if(isset($trainingMaster->training_compliance))
        {
            foreach ($trainingMaster->training_compliance as $key=>$TraingComp )
            {
                $User = $TraingComp->user;
                $userNameString = $User->username.' | '.$User->userfullname.' | '.$User->department->department;
                $selectedUsers[$TraingComp->user_id]=$userNameString;
            }
        }
        $this->loadModel('Departments');
        $this->loadModel('FunctionalRoles');
        $this->loadModel('CustomerLocations');
        $selected_departments = ($trainingMaster['selected_department'])?json_decode($trainingMaster['selected_department'],true):'';
        if(!empty($selected_departments))
        {
            $departments_list=$this->Departments->find('all',[
                'fields'=>'department'])
                ->where(['Departments.id IN'=>$selected_departments])->toArray();
                
                foreach($departments_list as $dept){
                    $departments[]= $dept['department'];
                }
                $trainingMaster['deartment_name']= implode(', ', $departments);
                
        }
        
        //Fetch Selected Functional Roles
        $selected_functional_role = isset($trainingMaster['selected_roles'])?json_decode($trainingMaster['selected_roles'],true):'';
        if($selected_functional_role != ''){
            $functional_roles_list=$this->FunctionalRoles->find('all',[
                'fields'=>'role_name'
            ])->where(['FunctionalRoles.id IN'=>$selected_functional_role])->toArray();
            
            foreach($functional_roles_list as $role){
                $functionalRoles[]= $role['role_name'];
            }
            $trainingMaster['functional_roles']= implode(', ', $functionalRoles);
        }
        
        $customerLocations = $this->CustomerLocations->find('list', ['keyField' => 'id','valueField' => 'name'])->where(["CustomerLocations.customer_id"=>$customer_id]);
        $selectedUsers = $this->getSelectedUsers($training_id);
        $customers = $this->TrainingMaster->Customer->find('list', ['keyField' => 'id','valueField'=>'company_name']);
        $this->loadModel('CustomerLocations');
        $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
        //Fetch customers location according to customer_id
        $customerLocations = $this->CustomerLocations->find('list', ['keyField' => 'id','valueField' => 'name'])->where(["CustomerLocations.customer_id"=>$customer_id]);
        
        $type=Configure::read('trainingtype');
        $frequency=Configure::read('trainingfrequency');
        $this->loadModel('FunctionalRoles');
        $this->loadModel('Departments');
        $roles = $this->FunctionalRoles->find('list', ['keyField' => 'id','valueField'=>'role_name'])->where(['FunctionalRoles.customer_id'=>$trainingMaster['customer_id']]);
        $departments = $this->Departments->find('list', ['keyField' => 'id','valueField'=>'department'])->where(['Departments.customer_id'=>$trainingMaster['customer_id']]);
        $this->set(compact('departments','roles','trainingMaster','type', 'customers','frequency','customer_id','selectedUsers','customerLocations','location_id'));
        
        
        $selectedUsers = $this->getSelectedUsers($training_id);
        $customers = $this->TrainingMaster->Customer->find('list', ['keyField' => 'id','valueField'=>'company_name']);
        
        $frequency=Configure::read('trainingfrequency');
        $evaluationtype=Configure::read('evaluationtype');
        $this->loadModel('FunctionalRoles');
        $this->loadModel('Departments');
        $this->loadModel('SectionMaster');
        $this->loadModel('DocMaster');
        $docMasterList = $this->DocMaster->find('all', ['fields' => ['id','title','doc_no']])->where(["DocMaster.ammend_status !=" => 'Y', "DocMaster.status" => 'Y']);
        $docmasterList = $docMasterList->map(function ($value, $key) {
            $docName = $value->doc_no . "|" . $value->title;
            return [
                'value' => $value->id,
                'text' => $docName,
            ];
        });
            
            $pluginArray=Configure::Read('capaArray');
            $references = $pluginArray['plugins_for_training'];
            
            $roles = $this->FunctionalRoles->find('list', ['keyField' => 'id','valueField'=>'role_name'])->where(['FunctionalRoles.customer_id'=>$trainingMaster['customer_id']]);
            $departments = $this->Departments->find('list', ['keyField' => 'id','valueField'=>'department'])->where(['Departments.customer_id'=>$trainingMaster['customer_id']]);
            $sectiondata = $this->SectionMaster->find('list', ['keyField' => 'id','valueField' => 'name'])->where(['SectionMaster.customer_id'=>$customer_id,'SectionMaster.customer_location_id'=>$session['base_location_id'],'SectionMaster.status'=>1]);
            $this->set(compact('references','docmasterList','evaluationtype','departments','roles','trainingMaster','type', 'customers','frequency','customer_id','selectedUsers','customerLocations','sectiondata'));
            
        $this->set(compact('trainingMaster','selectedUsers'));
    }
    
    public function mySession()
    {
       
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingIndex = $trainingCompliance;
        $session = $this->request->getSession()->read('Auth');
        if (!$this->Authorization->can($trainingCompliance, 'EvaluateBatch')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
       
            $user_id = $session->id;
            $this->loadModel('Tc.TrainingBatch');
            
        $condition = [
            //'TrainingBatch.instructor'=>$user_id,
            'TrainingMaster.customer_location_id' => $session['base_location_id'],
            'OR'=>['TrainingBatch.status !='=> 0,]
        ];
      //  debug($condition);die;
       $this->paginate = [
            'contain' => ['TrainingMaster',],
            'order' => ['id' => 'desc'],
            'conditions' => [$condition]
            
        ];
       $query = $this->Authorization->applyScope($this->TrainingBatch->find());
       $trainingBatch = $this->paginate($query);
        $this->set(compact('user_id','trainingBatch','trainingIndex'));
    }
    
    public function myTasks()
    {
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingIndex = $trainingCompliance;
        $session = $this->request->getSession()->read('Auth');
        $customer_id = $session['customer_id'];
        $location_id = $session['base_location_id'];
        if (!$this->Authorization->can($trainingCompliance, 'mytask')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        $user_id = $session->id;
        
        $condition = [
            'TrainingBatch.status'=>2,
            'TrainingMaster.customer_location_id' => $session['base_location_id']
        ];
        $this->loadModel('Tc.TrainingBatch');
        
        $this->paginate = [
            'contain' => ['TrainingMaster',],
            'order' => ['id' => 'asc'],
            'conditions' => [$condition]
            
        ];
        $trainingBatch = $this->paginate($this->TrainingBatch);
        
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('Tc.UserSelfTraining');
        $PendingTrainings = $this->TrainingMaster->UserSelfTraining->find('all',['contain'=>['TrainingMaster','Users'=>['Departments']]])->where(["UserSelfTraining.customer_id" => $customer_id,"UserSelfTraining.customer_location_id" => $location_id,'UserSelfTraining.is_approve'=>0])->toArray();
       // $PendingTrainings = $this->Authorization->applyScope($this->TrainingMaster->UserSelfTraining->find('all',['contain'=>['TrainingMaster','Users'=>['Departments']]]),'PendingApproval')->where(["UserSelfTraining.customer_id" => $customer_id,"UserSelfTraining.customer_location_id" => $location_id,'UserSelfTraining.is_approve'=>0])->toArray();
        $transPass = $this->transPass;
        $this->set(compact('transPass','user_id','trainingBatch','trainingIndex','customer_id','PendingTrainings'));
    }
    
    
    public function approveRejectBatch($id = null,$status = null)
    {
        $id = ($id == null) ? null : decryptVal($id);
        $status = ($status == null) ? null : decryptVal($status);
        
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $this->loadModel('Tc.TrainingBatch');
        $trainingIndex = $trainingCompliance;
        $session = $this->request->getSession()->read('Auth');
        if (!$this->Authorization->can($trainingCompliance, 'mytask')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        $trainingBatch = $this->TrainingBatch->get($id, [
            'contain' => ['TrainingMaster'],
        ]);
        
        
        if ($status == 'approve') {
            
           $trainingBatch['status'] = 3;
            $result = $this->TrainingBatch->save($trainingBatch);
            $this->TrainingCompliance->updateAll(["status"=>3],["training_batch_id"=>$id]);
        }
        
        elseif ($status == 'reject')
        {
            $trainingBatch['status'] = 1;
            $result = $this->TrainingBatch->save($trainingBatch);
            $this->TrainingCompliance->updateAll(["status"=>1],["training_batch_id"=>$id]);
        }
        
        if ($result) {
            $this->Flash->success(__('The My Task has been saved.'));
            
            return $this->redirect(['action' => 'myTasks']);
            
        }
        
    }
    
    public function approveselfTraining()
    {
        $this->Authorization->skipAuthorization();
        $this->autoRender = false;
        $this->loadModel('Tc.TrainingCompliance');
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $saveData['self_training_id'] = $this->request->getData('usertrgId');
        $saveData['approval_comment'] = $this->request->getData('Comment');
        if($this->request->getData('approveName') == 'approve'){
            $saveData['approve'] = $this->request->getData('approveName'); 
        }else if($this->request->getData('approveName') == 'reject'){
            $saveData['reject'] = $this->request->getData('approveName'); 
        }
        
        
        
//            if (!$this->Authorization->can($trainingCompliance, 'ApproveselfTraining')) {
//             $this->Flash->error(__('You are not authorized user to access!!!'));
//             return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
//         }
        $this->loadModel('UserSelfTraining');
        $session = $this->request->getSession()->read('Auth');
        $approve_by_id = $session->id;
        $customer_id = $session->customer_id;
        if ($this->request->is('post')) {
            $status = '';
            $saveData['approve_by'] = $approve_by_id;
            if (isset($saveData['reject'])) {
                $saveData['is_approve'] = 2;
                $status = 'Reject';
            }
            elseif(isset($saveData['approve']))
            {
                $saveData['is_approve'] = 1;
                $status = 'Approve';
            }
           
            if (isset($saveData['self_training_id'])) {
                $id = $saveData['self_training_id'];
                $selftrainingApproval = $this->UserSelfTraining->get($id);
                $trgIds = $selftrainingApproval['training_master_id'];
                $this->loadModel('Tc.TrainingMaster');
                $trainingData = $this->TrainingMaster->find('all', ['fields' => ['id','type','due_date']])->where(['id' => $trgIds])->toArray();
                $trgDuedate = $trainingData[0]->fduedate;
                $dateformatk=Configure::read('dateinputformat');
                $trgDuedate1 = DateTime::createFromFormat($dateformatk, $trgDuedate);
                $trgDuedate2 = $trgDuedate1->format('Y-m-d');
                $trgType = $trainingData[0]->type;
                if($trgType == 1){
                    $trgstatus = 0;
                    $actionurl = 'userunsheduletraining';
                }else{
                    $trgstatus = 1;
                    $actionurl = 'usertrainingindex';
                }
              
                $UserSelftraining = $this->UserSelfTraining->patchEntity($selftrainingApproval, $saveData);
                if ($result = $this->UserSelfTraining->save($UserSelftraining)) {
                    $selfTraining_id = $result->id;
                    
                   if( isset($saveData['approve']))
                   {
                       $trainingCompArray=[
                           'customer_id'=>$customer_id,
                           'training_id'=>$selftrainingApproval['training_master_id'],
                           'status'=>$trgstatus,
                           'created_by'=>$selftrainingApproval['user_id'],
                           'user_id'=>$selftrainingApproval['user_id'],
                           'due_date'=>$trgDuedate2
                       ];
                       
                       $trainingComp = $this->TrainingCompliance->newEmptyEntity();
                       $UsertrainingComp = $this->TrainingCompliance->patchEntity($trainingComp, $trainingCompArray);
                       $this->TrainingCompliance->save($UsertrainingComp);
                   }
                   
                      $notification = new SimpleNotification([
                            "notification_inbox_data" => [
                                "customer_id" => $customer_id,
                                "created_by" => $session['id'],
                                "user_type" => "Users",
                                "user_reference_id" => $selftrainingApproval['user_id'],
                                "title" => 'Training Compliance Request Is '.$status,
                                "comments" => "Your Training Compliance Approval Request is.".$status,
                                "plugin_name" => 'Tc',
                                "model_reference_name" => "TrainingCompliance",
                                "model_reference_id" => $selfTraining_id,
                                "action_link" => ["plugin" => 'Tc', "controller" => "TrainingCompliance", "action" => $actionurl]
                            ],
                        ]);
                        $notification->send();
                       
                        $this->response = $this->response->withType('application/json')
                        ->withStringBody(json_encode($saveData['is_approve']));
                        return NULL;

                }
            }
        }
    }
    
    public function evaluateUser() {
        
        $trainingIndex = $this->TrainingCompliance->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        $logged_user_id = $session->id;
        $customer_id = $session->customer_id;
        if (!$this->Authorization->can($trainingIndex, 'EvaluateUser')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        if ($this->request->is('post','put')) {
            $saveData = $this->request->getData();
            $status =  $saveData['TrainingCompliance']['status'];
            if (isset($saveData['TrainingCompliance']['id'])) {  
                $id= $saveData['TrainingCompliance']['id'];
                $trainingCompl = $this->TrainingCompliance->get($id, [
                    'contain' => ['TrainingMaster', 'Users'=>['Departments']],
                ]);
                $saveData['TrainingCompliance']['passing_score'] = $trainingCompl->training_master->passing_score;
                $trainingCompliance = $this->TrainingCompliance->patchEntity($trainingCompl, $saveData['TrainingCompliance']);
                
               if ($result = $this->TrainingCompliance->save($trainingCompliance)) {
                    $training_compliance_id = $result->get('id');
                    //if(isset($saveData['saveOnly'])){
                    if ($status != '') {
                        $this->loadModel('Tc.TrainingComplianceLog');
                        $status_log = $this->TrainingComplianceLog->newEntity([
                            'training_compliance_id' => $training_compliance_id,
                            'status_id' => $status,
                            'start_date' => date($this->DateTimeFormat),
                            'end_date' => date($this->DateTimeFormat),
                            'action_taken' => 'Submit',
                            'status_change_by' => $this->Authentication->getIdentity('User')->id,
                            'comments' => 'Training Evaluation Done. '
                        ]);
                        $this->TrainingComplianceLog->save($status_log);
                    }
                    
                    $notification = new SimpleNotification([
                        "notification_inbox_data" => [
                            "customer_id" => $customer_id,
                            "created_by" => $session['id'],
                            "user_type" => "Users",   // accepts User|Groups|Departments
                            "user_reference_id" => $saveData['TrainingCompliance']['user_id'], // relavtive id
                            "title" => 'Training Compliance Approval Request', // title of notification
                            "comments" => "Your Training Compliance Approval Request is.".$status, // content of notification
                            "plugin_name" => 'Tc', // for which plugin_name you are highlighting. if required
                            "model_reference_name" => "TrainingCompliance", // for which plugin reference name   if required
                            "model_reference_id" => $saveData['TrainingCompliance']['id'], //   if required
                            "action_link" => ["plugin" => 'Tc', "controller" => "TrainingCompliance", "action" => "usertrainingindex", encryptVal($saveData['TrainingCompliance']['user_id'])] // link to redirect to user.
                        ],
                    ]);
                    $notification->send();
                    $this->Flash->success(__('The Training Evaluation has been saved.'));
                    $this->autoRender = false;
                    $this->response = $this->response->withType('application/json')
                    ->withStringBody(json_encode($result));
                    return null;
                }
                
                   
            }
            
        }
       
        
   } 
    public function evaluateBatch($id = null)
    {
        $id = ($id == null) ? null : decryptVal($id);
        $this->loadModel('Tc.TrainingBatch');
        $trainingIndex = $this->TrainingCompliance->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        $user_id = $session->id;
        $customer_id = $session->customer_id;
        if (!$this->Authorization->can($trainingIndex, 'EvaluateBatch')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        $trainingBatch = $this->TrainingBatch->get($id, [
            'contain' => ['TrainingMaster', 'TrainingCompliance'=>['Users'=>['Departments']]],
        ]);
       
        
        if ($this->request->is('post')) {
            $saveData = $this->request->getData();  
            $traningData = $this->TrainingCompliance->newEntities($this->request->getData());
            $trainingCompliance = $this->TrainingCompliance->patchEntities($traningData, $saveData['TrainingCompliance']);
            $result = $this->TrainingCompliance->saveMany($trainingCompliance);
            if ($result) {
                $attachment = $saveData['training_batch']["training_evidence"];
                $Batch_id=$saveData['training_batch']["id"];
                $train_master_id=$saveData['training_batch']["training_master_id"];
                //  debug($saveData);exit;
                 $trainingBatch = $this->TrainingBatch->get($Batch_id);
                if ($attachment->getError() == 0) {
                    $filename = $attachment->getClientFilename();
                    $tmp_name = $attachment->getStream()->getMetadata('uri');
                    $filesPathNew = "training/" . $train_master_id . DS .$Batch_id. DS . $filename;
                    if (QMSFile::fileExistscheck($filesPathNew, $customer_id) == false) {
                        QMSFile::moveUploadedFile($tmp_name, "training/" . $train_master_id . DS .$Batch_id. DS . $filename, $customer_id);
                         $trainingBatch['training_evidence'] = $filename;
                         $trainingBatch['status'] = 2;
                        }
                }
                $trainingBatch['start_time'] = $saveData['training_batch']['start_time'] != '' ?$saveData['training_batch']['start_time']:$trainingBatch->start_time;
                $trainingBatch['end_time'] = $saveData['training_batch']['end_time'] != ''?$saveData['training_batch']['end_time']:$trainingBatch->end_time;
                $trainingBatch['total_duration'] = $saveData['training_batch']['total_duration'];
                $this->TrainingBatch->save($trainingBatch);
                $this->Flash->success(__('The Training Session has been saved.'));
                
                return $this->redirect(['action' => 'mySession']);
            }
            else {
                $this->Flash->error(__('The training evidence could not be saved. Please, try again.'));
            }
        }
       
        $this->set(compact('user_id','trainingBatch','trainingIndex'));
    }

    public function userspecialtrainingindex()
    {
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');

        $access_module = $this->request->getSession()->read('Auth')['customer_role']['rolewise_permissions'];
        $isATrue = false;
        foreach ($access_module as $rows) {
            if (($rows['can_view'] == 1 || $rows['can_create'] == 1 || $rows['can_access'] == 1) && $rows['plugins_module_id'] == 8) {
                $isATrue = true;
            }
        }

        $isAdd = ($this->Authorization->can($trainingCompliance, 'add')) ? 1 : 0;
        $condition = ['TrainingCompliance.status !=' => 5];
        $query = $this->request->getQuery('table_search');
        $customerLocationData = $this->request->getQuery('customer_locations_id');
        if ($customerLocationData != "") {
            $condition["AND"][] = ['OR' => ["customer_locations.id" => $customerLocationData]];
        }
        if ($query != null && trim($query, " ") != "") {
            $condition['or'][] = "(TrainingCompliance.score LIKE '%$query%' OR EmployeeMaster.first_name LIKE '%$query%' OR customer_locations.name LIKE '%$query%' OR departments.department LIKE '%$query%' OR TrainingCompliance.start_date LIKE '%$query%' OR TrainingCompliance.end_date LIKE '%$query%' )";
        }
        //debug($query);

        $condition = array_merge($condition, ['TrainingCompliance.customer_id' => $session['customer_id']]);

        //if($session['groups_id'] == 3){
        $this->loadModel('EmployeeMaster');
        $employee = $this->EmployeeMaster->find('all', ['conditions' => ['EmployeeMaster.user_id' => $this->Authentication->getIdentity('User')->id]])->first();

        if (!empty($employee)) {
            $condition = array_merge($condition, ['TrainingCompliance.employee_id' => $employee->id]);
        }
        // }


        $condition = array_merge($condition, ['TrainingCompliance.status !=' => 5, 'is_special' => 1]);



        $this->paginate = [
            'contain' => ['Customer', 'TrainingMaster', 'Departments', 'EmployeeMaster' => ['CustomerLocations']],
            'order' => ['id' => 'desc'],
            'conditions' => $condition,

        ];
        $trainingCompliance = $this->paginate($this->TrainingCompliance);
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('CustomerLocations');
        $trainingMaster = $this->TrainingMaster->find('list', ['keyField' => 'id', 'valueField' => 'training_name'])->where(['customer_id' => $session['customer_id']]);
        $CustomerLocations = $this->CustomerLocations->find('list', ['keyField' => 'id', 'valueField' => 'name'])->where(['customer_id' => $session['customer_id']]);

        $customers = $this->TrainingCompliance->Customer->find('list', ['keyField' => 'id', 'valueField' => 'company_name'])->where(['Customer.active' => 1]);
        $this->set(compact('CustomerLocations', 'customers', 'trainingMaster', 'isAdd', 'trainingCompliance', 'query','trainingIndex'));
    }

    /**
     * View method
     *
     * @param string|null $id Training Compliance id.
     * @return \Cake\Http\Response|null
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $id = ($id == null) ? null : decryptVal($id);
        $trainingCompliance = $this->TrainingCompliance->get($id, [
            'contain' => ['TrainingComplianceLog', 'Customer', 'Users', 'TrainingMaster', 'TrainingComplianceAttachment'],
        ]);
        if (!$this->Authorization->can($trainingCompliance, 'view')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }

        $this->set('trainingCompliance', $trainingCompliance);
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        //$employee_id=($employee_id==null)?null:decryptVal($employee_id);
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        if (!$this->Authorization->can($trainingCompliance, 'add')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }

        $session = $this->request->getSession()->read('Auth');
        $this->loadComponent('Common');
        //$this->Common->updateTrainingRoleMap($session['customer_id'],'applicable_for');
        //$this->Common->updateTrainingRoleMap($session['customer_id'],'completed_by',3);

        if ($this->request->is('post')) {
            $saveData = $this->request->getData(); //debug($saveData);die;
            $customer_id = $saveData['customer_id'];
            $training_id = $saveData['training_id'];
            $employee_id = $saveData['employee_id'];

           // $this->loadModel("CompanySettings");
            //$company_settings=$this->CompanySettings->find('all',array('conditions'=>array('CompanySettings.customer_id'=>$customer_id,'CompanySettings.active'=>1)));
      //      $company_settings = $this->CompanySettings->find('list', ['keyField' => 'setting_key', 'valueField' => 'value', 'conditions' => ['customer_id' => $customer_id, 'active' => 1]])->toArray();

            $this->loadModel('Tc.TrainingMaster');
            $trainingDetails = $this->TrainingMaster->get($training_id)->toArray();
            if (!empty($trainingDetails)) {
                $saveData['due_date'] = isset($trainingDetails['due_date']) ? $trainingDetails['due_date'] : '';
                $saveData['passing_score'] = isset($trainingDetails['passing_score']) ? $trainingDetails['passing_score'] : '';
                $saveData['duration'] = isset($trainingDetails['duration']) ? $trainingDetails['duration'] . ' ' . (isset($trainingDetails['duration_ext']) ? $trainingDetails['duration_ext'] : '') : '';
            }

            if (isset($saveData['training_compliance_attachment'])) {
                foreach ($saveData['training_compliance_attachment'] as $k => $file) {
                    $attachment = $file["file_name"];
                    if ($attachment->getError() == 0) {
                        $filename = date("YmdHis") . $attachment->getClientFilename();
                        $tmp_name = $attachment->getStream()->getMetadata('uri');
                        QMSFile::moveUploadedFile($tmp_name, "training_compliance" . DS . $filename, $customer_id);
                        $saveData["training_compliance_attachment"][$k]['file_name'] = $filename;
                    } else {
                        unset($saveData['training_compliance_attachment'][$k]);
                    }
                }
            } else {
                unset($saveData["training_compliance_attachment"]);
            }
            $comment = isset($saveData['comments']) ? $saveData['comments'] : '';
            //debug($saveData);die;
            $status = '';
            if (isset($saveData['saveOnly'])) {
                if ($session['groups_id'] == 3) {
                    $status = 1;
                } else {
                    $status = 2;
                }
            }
            if (isset($saveData['saveSendForApproval'])) {
                $status = 2;
            }
            if (isset($saveData['saveApprove'])) {
                $status = 3;
            }

            $trainingCompliance = $this->TrainingCompliance->patchEntity($trainingCompliance, $saveData);
            $trainingCompliance['created_by'] = $this->Authentication->getIdentity('User')->id;
            $trainingCompliance['status'] = $status;
            //debug($trainingCompliance);die;
            if ($result = $this->TrainingCompliance->save($trainingCompliance)) {
                $training_compliance_id = $result->get('id');
                //if(isset($saveData['saveOnly'])){
                if ($status != '') {
                    $this->loadModel('Tc.TrainingComplianceLog');
                    $status_log = $this->TrainingComplianceLog->newEntity([
                        'training_compliance_id' => $training_compliance_id,
                        'status_id' => $status,
                        'start_date' => date('Y-m-d h:i:s'),
                        'end_date' => date('Y-m-d h:i:s'),
                        'action_taken' => 'Submit',
                        'status_change_by' => $this->Authentication->getIdentity('User')->id,
                        'comments' => $comment
                    ]);
                    $this->TrainingComplianceLog->save($status_log);
                }
                if ($status == 3) {
                    $this->loadComponent('Common');
                    $this->Common->updateTrainingRoleMap($customer_id, 'completed_by', $training_id);
                }
                if ($status == 2 || $status == 3 || $status == 4) {
                    $this->loadModel("EmployeeMaster");
                    if ($employee_id != '') {
                        $employee = $this->EmployeeMaster->get($employee_id);
                    }
                    $title = CustomerCache::read('training_complience_email_added_title');
                    $comments = CustomerCache::read('training_complience_email_added_comment');
                    if ($status == 3) {
                        $title = CustomerCache::read('training_complience_added_approved_title');
                        $comments = CustomerCache::read('training_complience_added_approved_comment');
                    }
                    if ($status == 4) {
                        $title = CustomerCache::read('training_complience_email_added_title');
                        $comments = CustomerCache::read('training_complience_reject_comment');
                    }
                    if (!empty($employee)) {
                        if (!empty($employee['user_id'])) {
                            $notification = new SimpleNotification([
                                "notification_inbox_data" => [
                                    "customer_id" => $customer_id,
                                    "created_by" => $session['id'],
                                    "user_type" => "Users",   // accepts User|Groups|Departments
                                    "user_reference_id" => $employee_id, // relavtive id
                                    "title" => $title, // title of notification
                                    "comments" => $comments, // content of notification
                                    "plugin_name" => 'Tc', // for which plugin_name you are highlighting. if required
                                    "model_reference_name" => "TrainingCompliance", // for which plugin reference name   if required
                                    "model_reference_id" => $training_compliance_id, //   if required
                                    "action_link" => ["plugin" => 'Tc', "controller" => "TrainingCompliance", "action" => "view", $training_compliance_id] // link to redirect to user.
                                ],
                            ]);
                            $notification->send();

                            // Send Email

                            $options = [
                                'subject' => CustomerCache::read('training_complience_email_subject'),
                                'send_at' => date(CustomerCache::read('training_complience_email_send_at')),
                                'template' => 'trainingmail',
                                "layout" => "default",
                                "format" => "html",
                                "config" => "default",
                                "from_name" => CustomerCache::read('training_complience_email_from_name'),
                                "from_email" => CustomerCache::read('training_complience_email_from_email')
                            ];
                            $email_data = [
                                "title" => $title, // title of notification
                                "comments" => $comments, // content of notification
                            ];
                            $this->loadComponent('CommonMailSending');
                            $sendmail = $this->CommonMailSending->send_mail($employee['email'], $email_data, $options);
                        }
                    }
                }
                //}
                $this->Flash->success(__('The training compliance has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The training compliance could not be saved. Please, try again.'));
        }
        $employees = $this->TrainingCompliance->EmployeeMaster->find('list', ['keyField' => 'id', 'valueField' => function ($row) {
            return $row->first_name . ' ' . $row->last_name;
        }])->where(['EmployeeMaster.customer_id' => $session['customer_id']]);
        
       // $trainings = $this->TrainingCompliance->TrainingMaster->find('list', ['keyField'=>'id','valueField'=>'training_name'])->where(['TrainingMaster.customer_id'=>$session['customer_id']]);
        $this->set(compact('trainingCompliance','employees'));
    }

    public function addselftraining($employee_id = null, $training_id = null)
    {
        $employee_id = ($employee_id == null) ? null : decryptVal($employee_id);
        $training_id = ($training_id == null) ? null : decryptVal($training_id);
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        if (!$this->Authorization->can($trainingCompliance, 'addselftraining')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $this->loadModel('Tc.TrainingComplianceLog');
        $this->loadModel('Users');
        $session = $this->request->getSession()->read('Auth');
        $base_location_id = $session['base_location_id'];
        $customer_id = $session['customer_id'];
        if ($this->request->is('post')) {
            $saveData = $this->request->getData(); //debug($saveData);die;
            $customer_id = $saveData['customer_id'];
            $saveData['customer_location_id'] = $base_location_id;
            $saveData['is_approve'] = 0;
            $this->loadModel('UserSelfTraining');
            $selfTraining = $this->UserSelfTraining->newEmptyEntity();
            $UserSelftraining = $this->UserSelfTraining->patchEntity($selfTraining, $saveData);
            if ($result = $this->UserSelfTraining->save($UserSelftraining)) {
                $selfTraining_id = $result->id;
                
//                 $userData = $this->Users->find('all',['fields'=>['departments_id','functional_role_id']])->where(['id'=>$saveData['user_id']])->first();
//                 $trainingComplianceData = [
//                     'customer_id' => $customer_id,
//                     'user_id' => $saveData['user_id'],
//                     'training_id' => $saveData['training_master_id'],
//                     'is_special' => 0,
//                     'created_by' => $this->Authentication->getIdentity('User')->id,
//                     'status' => 1,
//                     'department_id' => $userData->departments_id,
//                 ];
            
//             $traningData = $this->TrainingCompliance->newEmptyEntity();
//             $trainingCompliance = $this->TrainingCompliance->patchEntity($traningData, $trainingComplianceData);
//             $this->TrainingCompliance->save($trainingCompliance);
            
                if (!empty($saveData['user_id'])) {
                            $notification = new SimpleNotification([
                                "notification_inbox_data" => [
                                    "customer_id" => $customer_id,
                                    "created_by" => $session['id'],
                                    "user_type" => "Users",   // accepts User|Groups|Departments
                                    "user_reference_id" => $saveData['user_id'], // relavtive id
                                    "title" => 'Training Compliance Approval Request', // title of notification
                                    "comments" => "Training Compliance Approval Request From User.", // content of notification
                                    "plugin_name" => 'Tc', // for which plugin_name you are highlighting. if required
                                    "model_reference_name" => "TrainingCompliance", // for which plugin reference name   if required
                                    "model_reference_id" => $selfTraining_id, //   if required
                                    "action_link" => ["plugin" => 'Tc', "controller" => "TrainingCompliance", "action" => "edit",$selfTraining_id] // link to redirect to user.
                                ],
                            ]);
                            $notification->send();
                        
                    }
               
                $this->Flash->success(__('The self training has been saved.'));
                
                // return $this->redirect(['action' => 'usertrainingindex']);
                return $this->redirect($this->request->getSession()->read('lastPages'));
            }
            $this->Flash->error(__('The training compliance could not be saved. Please, try again.'));
        }
        
        $transPass = $this->transPass;
       
//         $TrainingMaster = $this->TrainingCompliance->find()->select(['training_id' => 'training_id'])->where(["user_id" => $employee_id])->toArray();
//         $trainingids = []; 
//         foreach ($TrainingMaster as $train)
//         {
//             array_push($trainingids,$train->training_id);
//         }
        //debug($trainingids);die;
//         if (isset($trainingids) && !empty($trainingids)) {
//             $TrainingMaster = $this->TrainingCompliance->TrainingMaster->find('list', ['keyField' => 'id', 'valueField' => 'training_name'])->where(["customer_id" => $customer_id,"customer_location_id" => $base_location_id,'id NOT IN'=>$trainingids])->toArray();
//         }
//         else {
       $currentDate = Configure::read('datecompairformat');
       $currentDate = date($currentDate);
       $TrainingMaster = $this->TrainingCompliance->TrainingMaster->find('list', ['keyField' => 'id', 'valueField' => 'training_name'])->where(["customer_id" => $customer_id,"customer_location_id" => $base_location_id,'TrainingMaster.due_date >='=>$currentDate,'TrainingMaster.is_suspended'=>0])->toArray();
        //}
        
        $this->set(compact('transPass','employee_id', 'trainingCompliance', 'training_id','TrainingMaster'));
    }

    /**
     * Edit method
     *
     * @param string|null $id Training Compliance id.
     * @return \Cake\Http\Response|null Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null)
    {
        $this->loadComponent('Common');
        $session = $this->request->getSession()->read('Auth');
        $id = ($id == null) ? null : decryptVal($id);
        $trainingCompliance = $this->TrainingCompliance->get($id, [
            'contain' => [
                'TrainingComplianceAttachment',
                'TrainingComplianceLog' => ['StatusChangeByUser']
            ],
        ]);
        $training_id = $trainingCompliance['training_id'];
        //debug($trainingCompliance['training_id']);die;
        //fetch already status_id
        $this->loadModel('Tc.TrainingMaster');
        //     	if($trainingCompliance['is_special']== 1){
        //     	    $trainingsResult = $this->TrainingCompliance->TrainingMaster->find('all')->where(['TrainingMaster.customer_id'=>$id]);

        //     	    $trainings = $trainingsResult->map(function($value,$key){
        //     	        return [
        //     	            'text'=>$value->training_name,
        //     	            'value'=>$value->id,
        //     	            'data-duedate'=>$value->due_date
        //     	        ];
        //     	    });
        //     	}


        $prev_status_id = $trainingCompliance['status'];

        if (!$this->Authorization->can($trainingCompliance, 'edit')) {
            if ($trainingCompliance->status == 2) {
                $this->Flash->error(__('You dont have permission to update because it is in the proccess of approval'));
                return $this->redirect(['action' => 'index']);
            } elseif ($trainingCompliance->status == 3) {
                $this->Flash->error(__('You dont have permission to update request after approval !!!'));
                return $this->redirect(['action' => 'index']);
            } elseif ($trainingCompliance->status == 4) {
                $this->Flash->error(__('You dont have permission to update request.!!! Request is rejected by authority only person who created request can update.'));
                return $this->redirect(['action' => 'index']);
            }
            /* else{
                $this->Flash->error(__('You are not authorized user to access!!!'));
            } */
            // return $this->redirect(['action' => 'index']);
        }
        
        $session = $this->request->getSession()->read('Auth');
        if ($this->request->is(['patch', 'post', 'put'])) {
            $saveData = $this->request->getData();
            
            $customer_id = $saveData['customer_id'];
            $training_id = $saveData['training_id'];
            $employee_id = $saveData['user_id'];

            if (isset($saveData['training_compliance_attachment']) && $saveData['training_compliance_attachment'] != '') {
                foreach ($saveData['training_compliance_attachment'] as $k => $file) {
                    $attachment = $file["file_name"];
                    if ($attachment->getError() == 0) {
                        $filename = date("YmdHis") . $attachment->getClientFilename();
                        $tmp_name = $attachment->getStream()->getMetadata('uri');
                        QMSFile::moveUploadedFile($tmp_name, "training_compliance" . DS . $filename, $customer_id);
                        $saveData["training_compliance_attachment"][$k]['file_name'] = $filename;
                    } else {
                        unset($saveData['training_compliance_attachment'][$k]);
                    }
                }
            } else {
                unset($saveData["training_compliance_attachment"]);
            }
            $comment = isset($saveData['comments']) ? $saveData['comments'] : '';
            //debug($saveData);die;
            $status = '';
            $actionTaken = 'Submit';
            if (isset($saveData['saveOnly'])) {
                /* if($session['groups_id'] == 3){
                    $status=1;
                }else{
                    $status=2;
                } */
                $status = 1;
            }
            if (isset($saveData['saveSendForApproval'])) {
                $status = 2;
            }
            if (isset($saveData['saveApprove'])) {
                $status = 3;
                $actionTaken = 'Approve';
            }
            if (isset($saveData['saveReject'])) {
                $status = 4;
                $actionTaken = 'Reject';
            }

            $trainingCompliance = $this->TrainingCompliance->patchEntity($trainingCompliance, $saveData);
            $trainingCompliance['modified_by'] = $this->Authentication->getIdentity('User')->id;
            $trainingCompliance['status'] = $status;
            //debug($trainingCompliance);die;
            if ($result = $this->TrainingCompliance->save($trainingCompliance)) {
                $training_compliance_id = $result->get('id');
                if (isset($saveData['saveOnly']) || isset($saveData['saveSendForApproval']) || isset($saveData['saveApprove']) || isset($saveData['saveReject'])) {

                    $this->loadModel('TrainingComplianceLog');
//                     debug($id);die;
//                     $trainingComplianceLog = $this->TrainingComplianceLog->get($id);

                    /*  $data=[
                        'training_compliance_id'=>$training_compliance_id,
                        'status_id'=>$status,
                        'start_date'=>date('Y-m-d h:i:s'),
                        'end_date'=>date('Y-m-d h:i:s'),
                        'action_taken'=>$actionTaken,
                        'status_change_by'=>$this->Authentication->getIdentity('User')->id,
                        'comments'=>$comment
                    ]; */

                    /*  if($status == $prev_status_id){ 
                        $status_log = $this->TrainingComplianceLog->patchEntity($trainingComplianceLog, $data);
                    }else{  */

                    $status_log = $this->TrainingComplianceLog->newEntity([
                        'training_compliance_id' => $training_compliance_id,
                        'status_id' => $status,
                        'start_date' => date('Y-m-d h:i:s'),
                        'end_date' => date('Y-m-d h:i:s'),
                        'action_taken' => $actionTaken,
                        'status_change_by' => $this->Authentication->getIdentity('User')->id,
                        'comments' => $comment
                    ]);
                    //}
                    //debug($status_log);die;
                    $res = $this->TrainingComplianceLog->save($status_log);
                    //debug($res);die;
                }
                if ($status == 2 || $status == 3 || $status == 4) {
                    $this->loadModel("Users");
                    if ($employee_id != '') {
                        $employee= $this->Users->get($employee_id);
                    }
                    $title = 'New Training Compliance added';
                    $comments = "New training compliance added by authority.";
                    if ($status == 3) {
                        $title = 'New Training Compliance Added And Approved';
                        $comments = "New training compliance added and approved by authority.";
                    }
                    if ($status == 4) {
                        $title = 'New Training Compliance Added And Rejected';
                        $comments = "New training compliance added and rejected by authority.";
                    }
                    if (!empty($employee)) {
                        if (!empty($employee['user_id'])) {
                            $notification = new SimpleNotification([
                                "notification_inbox_data" => [
                                    "customer_id" => $customer_id,
                                    "created_by" => $session['id'],
                                    "user_type" => "Users",   // accepts User|Groups|Departments
                                    "user_reference_id" => $employee->id, // relavtive id
                                    "title" => $title, // title of notification
                                    "comments" => $comments, // content of notification
                                    "plugin_name" => 'Tc', // for which plugin_name you are highlighting. if required
                                    "model_reference_name" => "TrainingCompliance", // for which plugin reference name   if required
                                    "model_reference_id" => $training_compliance_id, //   if required
                                    "action_link" => ["plugin" => 'Tc', "controller" => "TrainingCompliance", "action" => "view", $training_compliance_id] // link to redirect to user.
                                ],
                            ]);
                            $notification->send();
                        }
                    }
                }
                if ($status == 3) {
                    
                    $this->Common->updateTrainingRoleMap($customer_id, 'completed_by', $training_id);
                }
                $this->Flash->success(__('The training compliance has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The training compliance could not be saved. Please, try again.'));
        }
        
        $getuserCond=array("Users.customer_id"=>$session->customer_id,"Users.active"=>1);
        $users=$this->Common->getUsersArray($getuserCond);
       

        $trainingsResult = $this->TrainingCompliance->TrainingMaster->find('all')->where(['TrainingMaster.customer_id' => $session['customer_id']]);

        $trainings = $trainingsResult->map(function ($value, $key) {
            return [
                'text' => $value->training_name,
                'value' => $value->id,
                'data-duedate' => $value->due_date
            ];
        });


        $this->set(compact('trainingCompliance', 'users', 'trainings', 'training_id'));
    }
    public function editselftraining($id = null)
    {
        $id = ($id == null) ? null : decryptVal($id);
        $trainingCompliance = $this->TrainingCompliance->get($id, [
            'contain' => [
                'TrainingComplianceAttachment', 'TrainingMaster',
                'TrainingComplianceLog' => ['StatusChangeByUser']
            ],
        ]);
        if (!$this->Authorization->can($trainingCompliance, 'editselftraining')) {
            if ($trainingCompliance->status == 2) {
                $this->Flash->error(__('You dont have permission to update because it is in the proccess of approval'));
            } elseif ($trainingCompliance->status == 3) {
                $this->Flash->error(__('You dont have permission to update request after approval !!!'));
            } elseif ($trainingCompliance->status == 4) {
                $this->Flash->error(__('You dont have permission to update request.!!! Request is rejected by authority only person who created request can update.'));
            } else {
                $this->Flash->error(__('You are not authorized user to access!!!'));
            }
            return $this->redirect(['action' => 'index']);
        }
        $employee_id = $trainingCompliance['employee_id'];
        $session = $this->request->getSession()->read('Auth');
        if ($this->request->is(['patch', 'post', 'put'])) {
            $saveData = $this->request->getData(); //debug($saveData);die;
            $customer_id = $trainingCompliance['customer_id'];
            $training_id = $trainingCompliance['training_id'];
            $log_id = $saveData['log_id'];

            if (isset($saveData['training_compliance_attachment']) && $saveData['training_compliance_attachment'] != '') {
                foreach ($saveData['training_compliance_attachment'] as $k => $file) {
                    $attachment = $file["file_name"];
                    if ($attachment->getError() == 0) {
                        $filename = date("YmdHis") . $attachment->getClientFilename();
                        $tmp_name = $attachment->getStream()->getMetadata('uri');
                        QMSFile::moveUploadedFile($tmp_name, "training_compliance" . DS . $filename, $customer_id);
                        $saveData["training_compliance_attachment"][$k]['file_name'] = $filename;
                    } else {
                        unset($saveData['training_compliance_attachment'][$k]);
                    }
                }
            } else {
                unset($saveData["training_compliance_attachment"]);
            }
            $comment = isset($saveData['comments']) ? $saveData['comments'] : '';
            //debug($saveData);die;
            $status = '';
            $action_taken = 'Submit';
            if (isset($saveData['saveOnly'])) {
                
                    $status = 2;
             }
            if (isset($saveData['saveSendForApproval'])) {
                $status = 2;
            }
            if (isset($saveData['saveApprove'])) {
                $status = 3;
            }
            if (isset($saveData['saveReject'])) {
                $status = 4;
            }

            $trainingCompliance = $this->TrainingCompliance->patchEntity($trainingCompliance, $saveData);
            $trainingCompliance['modified_by'] = $this->Authentication->getIdentity('User')->id;
            $trainingCompliance['status'] = $status;
            //debug($trainingCompliance);die;
            if ($result = $this->TrainingCompliance->save($trainingCompliance)) {
                $training_compliance_id = $result->get('id');

                $this->loadModel('TrainingComplianceLog');
                if ($log_id != '') {
                    $status_log = $this->TrainingComplianceLog->get($log_id);

                    if (!empty($status_log)) {
                        $status_log = $this->TrainingComplianceLog->patchEntity(
                            $status_log,
                            ['comments' => $comment]
                        );
                        $this->TrainingComplianceLog->save($status_log);
                    }
                }
                if (isset($saveData['saveOnly']) || isset($saveData['saveSendForApproval']) || isset($saveData['saveApprove']) || isset($saveData['saveReject'])) {

                    $status_log = $this->TrainingComplianceLog->newEntity([
                        'training_compliance_id' => $training_compliance_id,
                        'status_id' => $status,
                        'start_date' => date('Y-m-d h:i:s'),
                        'end_date' => date('Y-m-d h:i:s'),
                        'action_taken' => $action_taken,
                        'status_change_by' => $this->Authentication->getIdentity('User')->id,
                        'comments' => $comment
                    ]);
                    $this->TrainingComplianceLog->save($status_log);
                }
                if ($status == 2) {
                    $this->loadModel("Users");
                    if ($customer_id != '') {
                        $users = $this->Users->find('list', ['keyField' => 'id', 'valueField' => 'id', 'conditions' => ['customer_id' => $customer_id, 'groups_id IN' => [2, 4], 'active' => 1]])->toArray();
                    }
                    if (!empty($users)) {
                        foreach ($users as $user) {
                            $notification = new SimpleNotification([
                                "notification_inbox_data" => [
                                    "customer_id" => $customer_id,
                                    "created_by" => $session['id'],
                                    "user_type" => "Users",   // accepts User|Groups|Departments
                                    "user_reference_id" => $user, // relavtive id
                                    "title" => 'Training Compliance Approval Request', // title of notification
                                    "comments" => "Training Compliance Approval Request From User.", // content of notification
                                    "plugin_name" => 'Tc', // for which plugin_name you are highlighting. if required
                                    "model_reference_name" => "TrainingCompliance", // for which plugin reference name   if required
                                    "model_reference_id" => $training_compliance_id, //   if required
                                    "action_link" => ["plugin" => 'Tc', "controller" => "TrainingCompliance", "action" => "edit", $training_compliance_id] // link to redirect to user.
                                ],
                            ]);
                            $notification->send();
                        }
                    }
                }
                if ($status == 3) {
                    $this->loadComponent('Common');
                    $this->Common->updateTrainingRoleMap($customer_id, 'completed_by', $training_id);
                }
                $this->Flash->success(__('The training compliance has been saved.'));

                return $this->redirect(['action' => 'usertrainingindex']);
            }
            $this->Flash->error(__('The training compliance could not be saved. Please, try again.'));
        }
        $employees = $this->TrainingCompliance->EmployeeMaster->find('list', ['keyField' => 'id', 'valueField' => function ($row) {
            return $row->first_name . ' ' . $row->last_name;
        }])->where(['EmployeeMaster.customer_id' => $session['customer_id']]);
        $trainingLogs = $this->TrainingCompliance->TrainingComplianceLog->find('all', ['conditions' => ['TrainingComplianceLog.training_compliance_id' => $id, 'TrainingComplianceLog.status_id' => 1]])->last();
        $this->set(compact('trainingLogs', 'trainingCompliance', 'employees', 'employee_id'));
    }

    /**
     * Delete method
     *
     * @param string|null $id Training Compliance id.
     * @return \Cake\Http\Response|null Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $id = ($id == null) ? null : decryptVal($id);
        //$this->request->allowMethod(['post', 'delete']);
        //$trainingCompliance = $this->TrainingCompliance->get($id,['contain'=>['TrainingComplianceAttachment','TrainingComplianceLog']]);
        $trainingCompliance = $this->TrainingCompliance->get($id);
        if (!$this->Authorization->can($trainingCompliance, 'delete')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        //debug($trainingCompliance);die;
        $updateData = ['status' => 5];
        $trainingCompliance = $this->TrainingCompliance->patchEntity($trainingCompliance, $updateData);
        $trainingCompliance['modified_by'] = $this->Authentication->getIdentity('User')->id;
        //debug($trainingCompliance);die;
        if ($this->TrainingCompliance->save($trainingCompliance)) {
            $this->loadModel('Tc.TrainingComplianceLog');
            $status_log = $this->TrainingComplianceLog->newEntity([
                'training_compliance_id' => $id,
                'status_id' => 5,
                'start_date' => date('Y-m-d h:i:s'),
                'end_date' => date('Y-m-d h:i:s'),
                'action_taken' => 'Remove',
                'status_change_by' => $this->Authentication->getIdentity('User')->id,
                'comments' => 'Deleted'
            ]);
            $this->TrainingComplianceLog->save($status_log);
            $this->Flash->success(__('The training compliance has been deleted.'));
        } else {
            $this->Flash->error(__('The training compliance could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function getemptrainings()
    {
        $this->Authorization->skipAuthorization();
        $this->autoRender = false;
        $this->loadModel('TrainingRoleMap');
        $this->loadModel('EmployeeRoles');
        $this->loadModel('EmployeeMaster');
        $this->TrainingRoleMap->recursive = -1;
        $employeeid = $this->request->getData('employeeid');

        //$employeeid=1;
        $trainingdata = [];
        $employeeRoles = $this->EmployeeRoles->find('all', ['conditions' => ['EmployeeRoles.employee_id' => $employeeid]])->first();
        $employeeMaster = $this->EmployeeMaster->find('all', ['conditions' => ['EmployeeMaster.id' => $employeeid]])->first();
        if (!empty($employeeRoles) && !empty($employeeMaster)) {
            $role_id = $employeeRoles->role_id;
            $department_id = $employeeMaster->department_id;
            $trainingRoleMap = $this->TrainingRoleMap->find('all', [
                'conditions' => ['TrainingRoleMap.role_id' => $role_id, 'TrainingRoleMap.department_id' => $department_id],
                'contain' => ['TrainingMaster']
            ]);
            $trainingdata = $trainingRoleMap->map(function ($value, $key) {

                return [
                    'value' => $value->training_id,
                    'text' => !empty($value->training_master) ? $value->training_master->training_name : '',
                    'due_date' => !empty($value->training_master) ? $value->training_master->due_date : '',
                    'department_id' => $value->department_id,
                    'role_id' => $value->role_id,
                ];
            });
        }
        $lm = $trainingdata;
        $this->response = $this->response->withType('application/json')
            ->withStringBody(json_encode($lm));
        return NULL;
    }
    public function deleteattachment($attach_id = null, $customer_id = null)
    {
        $this->Authorization->skipAuthorization();
        $attach_id = ($attach_id == null) ? null : decryptVal($attach_id);
        $customer_id = ($customer_id == null) ? null : decryptVal($customer_id);
        $this->loadModel('Tc.TrainingComplianceAttachment');
        $attachment = $this->TrainingComplianceAttachment->get($attach_id);
        if (!empty($attachment)) {
            $training_id = $attachment['training_compliance_id'];
            if (trim($attachment['file_name']) != "") {
                $filesPathNew = "training_compliance" . DS . $attachment['file_name'];
                if (QMSFile::delete($filesPathNew, $customer_id)) {
                    if ($this->TrainingComplianceAttachment->delete($attachment)) {
                        $this->Flash->success(__('The training compliance attachment has been deleted.'));
                    } else {
                        $this->Flash->error(__('The training compliance attachment could not be deleted. Please, try again.'));
                    }
                }
            }
            return $this->redirect(['action' => 'edit', encryptVal($training_id)]);
        }
        return $this->redirect(['action' => 'index']);
    }
    public function completedtrainings()
    {
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        if (!$this->Authorization->can($trainingCompliance, 'completedtrainings')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }

        $employee_id = '';
        $condition = ['TrainingCompliance.status IN' => [3]];
        if ($session['groups_id'] != 1) {
            $condition = array_merge($condition, ['TrainingCompliance.customer_id' => $session['customer_id']]);
        }

        if ($session['groups_id'] != 1) {
            $this->loadModel('EmployeeMaster');
            $employee = $this->EmployeeMaster->find('all', ['conditions' => ['EmployeeMaster.user_id' => $this->Authentication->getIdentity('User')->id]])->first();
            if (!empty($employee)) {
                $employee_id = $employee->id;
                $condition = array_merge($condition, ['TrainingCompliance.employee_id' => $employee->id]);
            }
        }
        //if($session['groups_id'] != 3){

        //}
        $this->paginate = [
            'contain' => ['Customer', 'EmployeeMaster', 'TrainingMaster'],
            'conditions' => $condition
        ];
        $trainingCompliance = $this->paginate($this->TrainingCompliance);

        $this->set(compact('employee_id', 'trainingCompliance'));
    }
    public function importexcelfile()
    {

        $this->Authorization->skipAuthorization();
        $this->autoRender = false;
        $training_id = $this->request->getData('training_id');
        $excel_data = $this->request->getData('excel_data');
        $customer_id = $this->request->getData('customer_id');
        $action_by = $this->request->getData('action_by');

        $this->loadModel('Tc.TrainingMaster');
        $trainingDetails = $this->TrainingMaster->get($training_id)->toArray();
        if (!empty($trainingDetails)) {
            $saveData['due_date'] = isset($trainingDetails['due_date']) ? $trainingDetails['due_date'] : '';
            $saveData['passing_score'] = isset($trainingDetails['passing_score']) ? $trainingDetails['passing_score'] : '';
            $saveData['duration'] = isset($trainingDetails['duration']) ? $trainingDetails['duration'] . ' ' . (isset($trainingDetails['duration_ext']) ? $trainingDetails['duration_ext'] : '') : '';
        }
        //$training_id=3;$customer_id=1;$action_by=32;
        //$excel_data='[{"emp_id": "EMP003", "start_date": "2020-08-05", "end_date": "2020-09-15", "score": 78},{"emp_id": "EMP002", "start_date": "2020-08-05", "end_date": "2020-09-15", "score": 63}]';
        $excel_data = json_decode($excel_data); //debug($excel_data);die;

        $return_data = [];
        if (!empty($excel_data)) {
            foreach ($excel_data as $row) {

                if (!empty($row->emp_id)) {
                    $score = $row->score;
                    $this->loadModel('EmployeeMaster');
                    $this->loadModel('Tc.TrainingMaster');
                    $employee = $this->EmployeeMaster->find('all', ['contain' => ['EmployeeRoles'], 'conditions' => ['EmployeeMaster.emp_code' => $row->emp_id]])->first();

                    $trainingMaster = $this->TrainingMaster->get($training_id)->toArray();
                    if (!empty($employee)) {
                        $employee_id = $employee->id;
                        $user_id = $employee->user_id;

                        $role_id = !empty($employee['employee_roles']) ? $employee['employee_roles'][0]['role_id'] : '';
                        $department_id = isset($employee['department_id']) ? $employee['department_id'] : '';

                        $due_date = !empty($trainingMaster) ? $trainingMaster['due_date'] : '';
                        $passing_score = isset($trainingMaster['passing_score']) ? $trainingMaster['passing_score'] : '';

                        $duration = isset($trainingMaster['duration']) ? $trainingMaster['duration'] . ' ' . (isset($trainingMaster['duration_ext']) ? $trainingMaster['duration_ext'] : '') : '';
                        if ($role_id != '' && $department_id != '') {

                            $this->loadModel('TrainingRoleMap');
                            $trainingRoleMap = $this->TrainingRoleMap->find('all', ['conditions' => ['TrainingRoleMap.training_id' => $training_id, 'TrainingRoleMap.role_id' => $role_id, 'TrainingRoleMap.department_id' => $department_id]])->toArray();
                            $this->loadModel('TrainingCompliance');
                            if (!empty($trainingRoleMap)) {
                                $trainingCompliance = $this->TrainingCompliance->find('all', ['conditions' => [
                                    'TrainingCompliance.employee_id' => $employee_id,
                                    'TrainingCompliance.score' => $score,
                                    'TrainingCompliance.training_id' => $training_id,
                                    'TrainingCompliance.start_date' => $row->start_date,
                                    'TrainingCompliance.end_date' => $row->end_date,
                                ]])->toArray();

                                if (empty($trainingCompliance)) {
                                    $trainingCompliance = $this->TrainingCompliance->newEntity([
                                        'employee_id' => $employee_id,
                                        'score' => $score,
                                        'training_id' => $training_id,
                                        'start_date' => $row->start_date,
                                        'end_date' => $row->end_date,
                                        'customer_id' => $customer_id,
                                        'status' => 2,
                                        'created_by' => $action_by,
                                        'department_id' => $department_id,
                                        'role_id' => $role_id,
                                        'due_date' => $due_date,
                                        'passing_score' => $passing_score,
                                        'duration' => $duration,
                                    ]);
                                    //                                     debug($trainingCompliance);die;
                                    $save = $this->TrainingCompliance->save($trainingCompliance);
                                    if ($save) {
                                        $training_compliance_id = $save->get('id');
                                        $this->loadModel('Tc.TrainingComplianceLog');
                                        $status_log = $this->TrainingComplianceLog->newEntity([
                                            'training_compliance_id' => $training_compliance_id,
                                            'status_id' => 2,
                                            'start_date' => date('Y-m-d h:i:s'),
                                            'end_date' => date('Y-m-d h:i:s'),
                                            'action_taken' => 'Submit',
                                            'status_change_by' => $action_by,
                                            'comments' => "Auto submitted by attendance"
                                        ]);
                                        $this->TrainingComplianceLog->save($status_log);

                                        $title = 'New Training Compliance added';
                                        $comments = "New training compliance added by authority.";

                                        if (!empty($user_id)) {
                                            $notification = new SimpleNotification([
                                                "notification_inbox_data" => [
                                                    "customer_id" => $customer_id,
                                                    "created_by" => $action_by,
                                                    "user_type" => "Users",   // accepts User|Groups|Departments
                                                    "user_reference_id" => $user_id, // relavtive id
                                                    "title" => $title, // title of notification
                                                    "comments" => $comments, // content of notification
                                                    "plugin_name" => 'Tc', // for which plugin_name you are highlighting. if required
                                                    "model_reference_name" => "TrainingCompliance", // for which plugin reference name   if required
                                                    "model_reference_id" => $training_compliance_id, //   if required
                                                    "action_link" => ["plugin" => 'Tc', "controller" => "TrainingCompliance", "action" => "view", $training_compliance_id] // link to redirect to user.
                                                ],
                                            ]);
                                            $notification->send();
                                        }

                                        //array_push($return_data,["saved"=>true,"emp_id"=>$row->emp_id,"error"=>""]);
                                    } else {
                                        array_push($return_data, ["saved" => false, "emp_id" => $row->emp_id, "error" => "Failed to save."]);
                                    }
                                } else {
                                    array_push($return_data, ["saved" => false, "emp_id" => $row->emp_id, "error" => "Record already exist."]);
                                }
                            } else {
                                array_push($return_data, ["saved" => false, "emp_id" => $row->emp_id, "error" => "This training is not exist for this employee."]);
                            }
                        } else {
                            array_push($return_data, ["saved" => false, "emp_id" => $row->emp_id, "error" => "Employee role not exist."]);
                        }
                    } else {
                        array_push($return_data, ["saved" => false, "emp_id" => $row->emp_id, "error" => "Employee not exist."]);
                    }
                }
            }
        } //die;
        //array_push($return_data,["saved"=>false,"emp_id"=>"EMP003","error"=>"Employee not exist."]);
        //array_push($return_data,["saved"=>false,"emp_id"=>"EMP002","error"=>"gffh hg hgf."]);
        $lm = $return_data;
        $this->response = $this->response->withType('application/json')
            ->withStringBody(json_encode($lm));
        return NULL;
    }
    public function trainingdetails()
    {
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        if (!$this->Authorization->can($trainingCompliance, 'trainingdetails')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $this->loadModel('Tc.TrainingMaster');


        /* $this->paginate = [
            'contain' => ['TrainingRoleMap','TrainingCompliance'],
            'conditions'=>$condition
        ];
        $trainingMaster = $this->paginate($this->TrainingMaster); */
        $query = $this->request->getQuery('filter');
        //debug($this->request->getQuery('filter'));
        if ($query == 'employeewise') {
            $this->loadModel('EmployeeMaster');
            $employeeMaster = $this->EmployeeMaster->find('all', [
                'contain' => [
                    'EmployeeRoles', 'EmployeeRoles.FunctionalRoles',
                    'TrainingCompliance', 'TrainingCompliance.TrainingMaster', 'Departments',
                ],
                'conditions' => ['EmployeeMaster.customer_id' => $session['customer_id'], 'EmployeeMaster.active' => 1]
            ]);
            //debug($employeeMaster);
            $this->set(compact('employeeMaster'));
        } else {
            $condition = ['TrainingMaster.customer_id' => $session['customer_id']];
            $trainingMaster = $this->TrainingMaster->find('all', [
                'contain' => ['TrainingRoleMap', 'TrainingCompliance', 'TrainingMasterDate' => ['sort' => ['id' => 'desc']]],
                'conditions' => $condition
            ])->toArray();

            if (!empty($trainingMaster)) { //debug($trainingMaster);
                foreach ($trainingMaster as $k => $trainingmaster) {
                    if (isset($trainingmaster['training_role_map']) && count($trainingmaster['training_role_map']) > 0) {
                        $employeeMaster = [];
                        foreach ($trainingmaster['training_role_map'] as $rolemap) {
                            $this->loadModel('EmployeeMaster');
                            $this->loadModel('EmployeeRoles');
                            $this->loadModel('TrainingRoleMap');
                            $employeeRoles = $this->EmployeeRoles->find('all', ['conditions' => ['EmployeeRoles.role_id' => $rolemap->role_id]])->toArray();
                            //debug($trainingmaster->id);debug($employeeRoles);
                            if (!empty($employeeRoles)) {
                                $conditions = ['TrainingCompliance.status !=' => 5, 'TrainingCompliance.training_id' => $trainingmaster['id']];
                                $limit = 1;
                                if ($trainingmaster->frequency == '1-in-y') {
                                    $conditions = array_merge($conditions, ['TrainingCompliance.end_date LIKE' => date('Y') . '%']);
                                } else if ($trainingmaster->frequency == '2-in-y') {
                                    $conditions = array_merge($conditions, ['TrainingCompliance.end_date LIKE' => date('Y') . '%']);
                                    $limit = 2;
                                }/* else if($trainingmaster->frequency == 'once'){
                                    $conditions=['TrainingCompliance.status !='=>5];
                                    } */
                                $employeeMasterRecord = $this->EmployeeMaster->find(
                                    'all',
                                    [
                                        'contain' => [
                                            'EmployeeRoles', 'EmployeeRoles.FunctionalRoles', 'Departments', 'TrainingCompliance' =>
                                            function (Query $q) use ($conditions, $limit) {
                                                return $q->order(['id' => 'desc'])->where($conditions)->limit($limit);
                                            }
                                        ],
                                        'conditions' => [
                                            'EmployeeMaster.department_id' => $rolemap->department_id,
                                            'EmployeeMaster.id IN' => array_column($employeeRoles, 'employee_id'),
                                        ]
                                    ]
                                )->toArray();
                                if (!empty($employeeMasterRecord)) {
                                    foreach ($employeeMasterRecord as $empRow) {
                                        array_push($employeeMaster, $empRow);
                                    }
                                }
                            }
                        } //debug($employeeMaster);
                        $trainingMaster[$k]['employeeMaster'] = $employeeMaster;
                    }
                }
            }
            $query = 'trainingwise';
            $this->set(compact('trainingMaster'));
        }
        $this->set(compact(/* 'employee_id', */'query'));
    }
    public function addspecialtraining()
    {
        //$employee_id=($employee_id==null)?null:decryptVal($employee_id);
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        if (!$this->Authorization->can($trainingCompliance, 'add')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }

        $session = $this->request->getSession()->read('Auth');
        $this->loadComponent('Common');
        //$this->Common->updateTrainingRoleMap($session['customer_id'],'applicable_for');
        //$this->Common->updateTrainingRoleMap($session['customer_id'],'completed_by',3);

        if ($this->request->is('post')) {
            $saveData = $this->request->getData();
            $customer_id = $saveData['customer_id'];
            $training_id = $saveData['training_id'];
            $employee_id = $saveData['employee_id'];


            $this->loadModel('Tc.TrainingMaster');
            $trainingDetails = $this->TrainingMaster->get($training_id)->toArray();
            if (!empty($trainingDetails)) {
                $saveData['due_date'] = isset($trainingDetails['due_date']) ? $trainingDetails['due_date'] : '';
                $saveData['passing_score'] = isset($trainingDetails['passing_score']) ? $trainingDetails['passing_score'] : '';
                $saveData['duration'] = isset($trainingDetails['duration']) ? $trainingDetails['duration'] . ' ' . (isset($trainingDetails['duration_ext']) ? $trainingDetails['duration_ext'] : '') : '';
            }
            if (isset($saveData['training_compliance_attachment'])) {
                foreach ($saveData['training_compliance_attachment'] as $k => $file) {
                    $attachment = $file["file_name"];
                    if ($attachment->getError() == 0) {
                        $filename = date("YmdHis") . $attachment->getClientFilename();
                        $tmp_name = $attachment->getStream()->getMetadata('uri');
                        QMSFile::moveUploadedFile($tmp_name, "training_compliance" . DS . $filename, $customer_id);
                        $saveData["training_compliance_attachment"][$k]['file_name'] = $filename;
                    } else {
                        unset($saveData['training_compliance_attachment'][$k]);
                    }
                }
            } else {
                unset($saveData["training_compliance_attachment"]);
            }
            $comment = isset($saveData['comments']) ? $saveData['comments'] : '';
            //debug($saveData);die;
            $status = '';
            //start->commented on 02-10-2021
            /* if(isset($saveData['saveOnly'])){
                if($session['groups_id'] == 3){
                    $status=1;
                }else{
                    $status=2;
                }
                
            }
            if(isset($saveData['saveSendForApproval'])){
                $status=2;
            } */
            //End
            if (isset($saveData['saveOnly'])) {
                $status = 1;
            }

            /*------------*/
            if (isset($saveData['saveApprove'])) {
                $status = 3;
            }
            $saveData['is_special'] = 1;
            $trainingComplianceData = [];
            $count = 0;
            foreach ($saveData['employee_id'] as $key => $value) {
                $count = $count + 1;
                $trainingComplianceData[$count] = $saveData;
                $trainingComplianceData[$count]['employee_id'] = $value;
                $trainingComplianceData[$count]['created_by'] = $this->Authentication->getIdentity('User')->id;
                $trainingComplianceData[$count]['status'] = $status;
            }
            $traningData = $this->TrainingCompliance->newEntities($this->request->getData());
            $trainingCompliance = $this->TrainingCompliance->patchEntities($traningData, $trainingComplianceData);
            if ($result = $this->TrainingCompliance->saveMany($trainingCompliance)) {
                foreach ($result as $traningKey => $traningValue) {
                    $training_compliance_id = $traningValue['id'];
                    //if(isset($saveData['saveOnly'])){
                    if ($status != '') {
                        $this->loadModel('Tc.TrainingComplianceLog');
                        $status_log = $this->TrainingComplianceLog->newEntity([
                            'training_compliance_id' => $training_compliance_id,
                            'status_id' => $status,
                            'start_date' => date('Y-m-d h:i:s'),
                            'end_date' => date('Y-m-d h:i:s'),
                            'action_taken' => 'Submit',
                            'status_change_by' => $this->Authentication->getIdentity('User')->id,
                            'comments' => $comment
                        ]);
                        $this->TrainingComplianceLog->save($status_log);
                    }
                    if ($status == 3) {
                        $this->loadComponent('Common');
                        $this->Common->updateTrainingRoleMap($customer_id, 'completed_by', $training_id);
                    }
                    if ($status == 1 || $status == 2 || $status == 3 || $status == 4) {
                        $title = 'New Training Compliance added';
                        $comments = "New training compliance added by authority.";
                        if ($status == 3) {
                            $title = 'New Training Compliance Added And Approved';
                            $comments = "New training compliance added and approved by authority.";
                        }
                        if ($status == 4) {
                            $title = 'New Training Compliance Added And Rejected';
                            $comments = "New training compliance added and rejected by authority.";
                        }
                        foreach ($employee_id as $key => $employeeId) {

                            if ($employee_id != '') {
                                $this->loadModel("EmployeeMaster");
                                $employee = $this->EmployeeMaster->get($employeeId);
                                if (!empty($employee)) {
                                    if (!empty($employee['user_id'])) {
                                        $notification = new SimpleNotification([
                                            "notification_inbox_data" => [
                                                "customer_id" => $customer_id,
                                                "created_by" => $session['id'],
                                                "user_type" => "Users",   // accepts User|Groups|Departments
                                                "user_reference_id" => $employee['user_id'], // relavtive id
                                                "title" => $title, // title of notification
                                                "comments" => $comments, // content of notification
                                                "plugin_name" => 'Tc', // for which plugin_name you are highlighting. if required
                                                "model_reference_name" => "TrainingCompliance", // for which plugin reference name   if required
                                                "model_reference_id" => $training_compliance_id, //   if required
                                                "action_link" => ["plugin" => 'Tc', "controller" => "TrainingCompliance", "action" => "view", $training_compliance_id] // link to redirect to user.
                                            ],
                                        ]);
                                        $notification->send();
                                    }
                                }
                            }
                        }
                    }
                    //}
                    $this->Flash->success(__('The training compliance has been saved.'));

                    return $this->redirect(['action' => 'index']);
                }
            }
            $this->Flash->error(__('The training compliance could not be saved. Please, try again.'));
        }
        $employeesResult = $this->TrainingCompliance->find('all', ['contain' => ['Users']])->where(['Users.customer_id' => $session['customer_id']]);
//         $employees = $employeesResult->map(function ($value, $key) {
//             return [
//                 'text' => $value->emp_code . ' | ' . $value->first_name . ' ' . $value->last_name,
//                 'value' => $value->id,
//                 'data-dept' => $value->department_id,
//                 'data-role' => isset($value->employee_roles[0]['role_id']) ? $value->employee_roles[0]['role_id'] : ''
//             ];
//         });

       
        $getuserCond=array("Users.customer_id"=>$session->customer_id,"Users.active"=>1);
        $users=$this->Common->getUsersArray($getuserCond);
       $trainingsResult = $this->TrainingCompliance->TrainingMaster->find('all')->where(['TrainingMaster.customer_id' => $session['customer_id']]);

        $trainings = $trainingsResult->map(function ($value, $key) {
            return [
                'text' => $value->training_name,
                'value' => $value->id,
                'data-duedate' => $value->due_date
            ];
        });
        $this->set(compact('trainingCompliance', 'users', 'trainings'));
    }

    public function assignTrainingToDocument($docId = null, $nextStatusId = null, $prevStatusId = null, $statusId = null)
    {
        //$employee_id=($employee_id==null)?null:decryptVal($employee_id);
        $this->loadModel('Tc.TrainingBatch');
        $this->loadComponent('Common');
        $this->loadModel("Departments");
        $this->loadModel("CustomerRoles");
        $this->loadModel('DocMaster');
        $this->loadModel('DocStatusLog');
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('Tc.TrainingComplianceLog');
        $this->loadModel('Tc.SectionMaster');
        $this->loadModel('DocStatusMaster');
        $this->loadModel('Users');
        $this->loadComponent('WiseWorks');   
        $isTransPassword = CustomerCache::read("transactional_password_required");
        if ($isTransPassword == 'Y') {
            $transPass  = 1;
        }
        else {
            $transPass = 0;
        }
        $docId = ($docId == null) ? null : decryptVal($docId);
        $prevStatusId = ($prevStatusId == null) ? null : decryptVal($prevStatusId);
        $nextStatusId = ($nextStatusId == null) ? null : decryptVal($nextStatusId);
        $statusId = ($statusId == null) ? null : decryptVal($statusId);
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingBatch = $this->TrainingBatch->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        $loggedUserId = $this->request->getSession()->read('Auth')['id'];
        
        if (!$this->Authorization->can($trainingCompliance, 'assignTrainingToDocument')) {
            $this->Flash->error(__('You are not authorized to access this page!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        


        // die;
        $customer_id = $this->request->getSession()->read('Auth')['customer_id'];
        $customer_locations_id = $this->request->getSession()->read('Auth')['base_location_id'];
        $session = $this->request->getSession()->read('Auth');
        $location_id = $this->request->getSession()->read('Auth')['base_location_id'];
        $loggedUserEmailId = $this->request->getSession()->read('Auth')['email'];
        $plugin=$this->request->getParam('plugin');
        $controller=$this->request->getParam('controller');
        
        $depts = $this->Departments->find('list', ['keyField' => 'id', 'valueField' => 'department'])->where(["Departments.customer_id" => $customer_id])->toArray();
       
        $funcRoles = $this->CustomerRoles->find('list', ['keyField' => 'id', 'valueField' => 'roles_name'])->where(["CustomerRoles.customer_id" => $customer_id])->toArray();

        
        $docMaster = $this->DocMaster->find('all')
            ->contain([
                'DocRevisions','DocChildMaster'=>['DocMaster'=>['DocRevisions'],'conditions'=>['doc_type'=>'sub_document']]
            ])
            ->where(['id' => $docId])->first()->toArray();
         $revId = $docMaster['doc_revisions'][0]['id'];  
         $datetime  = CustomerCache::read("datetimeformat");
          if($datetime==null){
              $datetime  = CustomerCache::read("datetimeformat",null,0);
          }
          if ($datetime == null){
              $datetime  = Configure::read("datetimeformat");
          }
        $doc_status_master = $this->DocStatusMaster->find('list', ['keyValue' => 'id', 'valueField' => 'form_name'])->toArray();
        $count = count($doc_status_master);
        $docMasterController = new DocMasterController();
        $lastStepId = $docMasterController->getLastStepId('Doc');
        if ($this->request->is('post')) {
            $saveData = $this->request->getData();// debug($saveData);exit;
            $customer_id = $saveData['customer_id'];
            $training_id = $saveData['training_id'];
            $employee_id = $saveData['employees'];
            $batch_no = $saveData['batch_no'];
            $saveData['training_master']['selected_department'] = json_encode($saveData['department_id']);
            $saveData['training_master']['selected_roles'] = json_encode($saveData['functional_role_id']);
            //debug($saveData);
            $batch_data = $this->TrainingBatch->find('all')
            ->where(['batch_no' => $batch_no,'training_master_id'=>$training_id])->toArray();
            
            $plugin = NULL;
            $this->loadComponent('Common');
            $this->Common->close_notification($plugin,'TrainingCompliance',$docId);
            
            if(count($batch_data)>0){
                $this->Flash->error(__('The training compliance could not be saved Training Session No. already Exists. Please, try again.'));
                return $this->redirect(['action' => 'assignTrainingIndex',encryptVal($docId)]);
            }else{
            $trainingDetails = $this->TrainingMaster->get($training_id);
            if (!empty($trainingDetails)) {
                
                $functional_role_id = !empty($saveData['functional_role_id']) ? $saveData['functional_role_id'] : [];
                foreach($functional_role_id as $k=>$role){
                    $saveData['training_roles'][$k]['functional_role_id']=(int)$role;
                }
                $training_master = isset($saveData['training_master']) ? $saveData['training_master'] : '';
                $m_array =  array_merge($training_master,
                ['training_for_model_name' => "DocMaster",  'training_for_model_id' => $docId,'id'=>$training_id]);
                 $saveData['training_master'] = $m_array;
                 $saveData['training_master']['training_sections'] = $saveData['training_sections'];
                 $saveData['training_master']['training_roles'] =  isset($saveData['training_roles'])?$saveData['training_roles']:[];
                 $trainingDetails = $this->TrainingMaster->get($training_id,['contain'=>['TrainingSections']]);
                 $trainingDetails = $this->TrainingMaster->patchEntity($trainingDetails, $saveData['training_master'],['associated'=>['TrainingRoles','TrainingSections']]);
                 $trainingDetails = $this->TrainingMaster->save($trainingDetails);
            }
           
            if (isset($saveData['training_compliance_attachment'])) {
                foreach ($saveData['training_compliance_attachment'] as $k => $file) {
                    $attachment = $file["file_name"];
                    if ($attachment->getError() == 0) {
                        $filename = date("YmdHis") . $attachment->getClientFilename();
                        $tmp_name = $attachment->getStream()->getMetadata('uri');
                        QMSFile::moveUploadedFile($tmp_name, "training_compliance" . DS . $filename, $customer_id);
                        $saveData["training_compliance_attachment"][$k]['file_name'] = $filename;
                    } else {
                        unset($saveData['training_compliance_attachment'][$k]);
                    }
                }
            } else {
                unset($saveData["training_compliance_attachment"]);
            }
            $comment = isset($saveData['comments']) ? $saveData['comments'] : '';
            
            $status = 0;
            $step_completed=0;
            $action_taken = 'Submit';
            
            if (isset($saveData['saveOnly'])) {
                $status = 0;
                $step_completed = 0;
                $action_taken = 'Submit';
            }

            /*------------*/
            if (isset($saveData['saveApprove'])) {
                $status = 1;
                $step_completed = 1;
                $action_taken = 'Approve';
            }
           

             //debug($saveData);exit;
            //Create Batch 
            
            foreach ($saveData['training_session_slots'] as $k=>$slot)
            {
                $slotDate = new FrozenDate($slot['slot_date']);
                $saveData['training_session_slots'][$k]['slot_date'] = $slotDate;              
                $saveData['training_session_slots'][$k]['training_master_id'] = $saveData['training_id'];
            }
            $TrainingBatch = $this->TrainingBatch->newEmptyEntity();

            $saveData['training_master_id'] = $saveData['training_id'];
            $saveData['training_status_log'][0]['training_master_id'] = $saveData['training_id'];
            $saveData['training_status_log'][0]['step_completed'] = $step_completed;
            $saveData['training_status_log'][0]['is_complete'] = $step_completed;
            $saveData['from_date'] = new FrozenDate($saveData['from_date']);
            $saveData['to_date'] = new FrozenDate($saveData['to_date']);
            $saveData['training_for_model_name'] = "DocMaster";
            $saveData['training_for_model_id'] = $docId;
            $saveData['status'] = $status;

            if($saveData['instructor_type'] == 'external'){
                $saveData['instructor_external'] = $saveData['instructor'];
            }
     
            
            $TrainingBatch = $this->TrainingBatch->patchEntity($TrainingBatch, $saveData,['associated'=>['TrainingStatusLog','TrainingSessionSlots']]);
            $trainingBatch = $this->TrainingBatch->save($TrainingBatch);
            $saveData['is_special'] = 0;
            $trainingComplianceData = [];
            $count = 0;
            $due_date = $trainingDetails['due_date'];
//             die;
            if ($saveData['type'] == 2) {
                $trainstatus = 1;
            }
            else {
                $trainstatus = 0;
            }
            
            foreach ($saveData['employees'] as $key => $value) {
                $count = $count + 1;
                $userData = $this->Users->find('all',['fields'=>['departments_id','functional_role_id']])->where(['id'=>$value])->first();
                $trainingComplianceData[] = [
                    'customer_id' => $saveData['customer_id'],
                    'user_id' => $value,
                    'training_id' => $saveData['training_master']['id'],
                    'training_batch_id' => isset($trainingBatch['id'])?$trainingBatch['id']:NULL,
                    'start_date' => $saveData['from_date'],
                    'end_date' => $saveData['to_date'],
                    'due_date' => $due_date,
                    'is_special' => 0,
                    'passing_score' => $saveData['training_master']['passing_score'],
                    'duration' => 0,
                    'created_by' => $this->Authentication->getIdentity('User')->id,
                    'status'=>$trainstatus,
                    'department_id' => $userData->departments_id,
                ];
            }
            $traningData = $this->TrainingCompliance->newEntities($trainingComplianceData);
            $trainingCompliance = $this->TrainingCompliance->patchEntities($traningData, $trainingComplianceData);
//            debug($trainingCompliance);die;
            if ($result = $this->TrainingCompliance->saveMany($trainingCompliance)) {
                //  Document Status Log
                
                if(!empty($saveData['employees'])){
                    foreach ($saveData['employees'] as $employee){
                        if(!empty($employee)){
                            $notificationData = [
                                'selected_users' => $employee,
                                'title' =>$trainingDetails->training_name,
                                'training_name' =>$trainingDetails->training_name,
                                'customer_id'=>$customer_id,
                                'notification_identifier'=>'add_training',
                                "action_link"=>["plugin"=>'Tc', "controller"=>"TrainingCompliance","action"=>"usertrainingindex"] // link to redirect to user.
                            ];
                            
                            $this->loadComponent('CommonMailSending');
                            $this->CommonMailSending->selected_email_details($customer_id,$location_id,$session->id,$notificationData,$loggedUserEmailId);
                            
                            $this->loadComponent('QmsNotification');
                            $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$location_id,$session->id,$notificationData,$session->$location_id);
                            
                        }
                    }
                }

                $uncomplete = $this->TrainingCompliance->find('all', ["contain" => ["TrainingBatch", "TrainingMaster"], "conditions" => ['TrainingBatch.training_for_model_name' => 'DocMaster', 'TrainingBatch.training_for_model_id' => $docId, 'TrainingBatch.status IN' => [0]]])->toArray();
                // debug($uncomplete);exit;
                if (empty($uncomplete)) {
                    $doc_step_completed = 1;
                } else {
                    $doc_step_completed = 0;
                }
                $docMaster = $this->DocMaster->get($docId, ["contain" => [
                    'DocRevisions',
                ]]);
                
                $docMaster = $this->DocMaster->patchEntity($docMaster, $saveData);
                $docMaster['doc_status_master_id'] = $statusId;
                unset($docMaster['doc_status_log']);
                $docresult = $this->DocMaster->save($docMaster);
                if($docresult){
                $log_data = $saveData['doc_status_log'];
                if ($log_data != '') {
                    $next_action_by = $log_data[0]['next_action_by'];

                    $log_id = $log_data[0]['id'];
                    if ($log_id != '') {
                        $docStatusLog = $this->DocStatusLog->get($log_id);
                        $statusLogData = [
                            'step_completed' => $doc_step_completed,
                            'comments' => "Document Training Assign",
                            'next_action_by' => '',
                            'action_by' => $this->Authentication->getIdentity('User')->id,
                            'action_taken' => $action_taken,
                        ];
                        $docStatusLog = $this->DocStatusLog->patchEntity($docStatusLog, $statusLogData);
                        $docStatusLog = $this->DocStatusLog->save($docStatusLog);
                    } else {
                        $docStatusLog = $this->DocStatusLog->newEntity([
                            'doc_master_id' => $docresult['id'],
                            'doc_revision_id' => $docresult['doc_revisions'][0]['id'],
                            'doc_status_master_id' => $statusId,
                            'start_date' =>new FrozenTime(date($datetime)),
                            'end_date' =>new FrozenTime(date($datetime)),
                            'step_completed' => $doc_step_completed,
                            'comments' => "Document Training Assign",
                            'next_action_by' => '',
                            'action_by' => $this->Authentication->getIdentity('User')->id,
                            'action_taken' => $action_taken,
                            'doc_data' => ''
                        ]);
                        $docStatusLog = $this->DocStatusLog->save($docStatusLog);
                    }
                }


                $this->loadModel('DocChildMaster');
                $this->loadModel('DocStatusLog');
                $child_doc = $this->DocChildMaster->find('all')
                    ->contain(['DocChildDetail'=>['DocRevisions']])
                    ->where(['DocChildMaster.doc_master_id' => $docId,'doc_type'=>'sub_document','DocChildDetail.doc_status_master_id <'=>$lastStepId])->toArray();
              
                foreach($child_doc as $key=>$child){
                    $child_doc_master = $child['doc_child_detail'];
                    $this->DocMaster->updateAll(['doc_status_master_id'=>$statusId,'has_training'=>1],['id'=> $child_doc_master['id']]);
                    $childStatus =  $this->DocStatusLog->find()->where(['doc_master_id'=>$child_doc_master['id'],'doc_status_master_id'=>$statusId])->last();
                    if (!empty($childStatus)) {
                        $childStatusLog = $this->DocStatusLog->get($childStatus['id']);
                        $statusLogData = [
                            'step_completed' => $doc_step_completed,
                            'comments' => "Document Training Assign",
                            'next_action_by' => '',
                            'action_by' => $this->Authentication->getIdentity('User')->id,
                            'action_taken' => $action_taken,
                        ];
                        $childStatusLog = $this->DocStatusLog->patchEntity($childStatusLog, $statusLogData);
                        $childStatusLog = $this->DocStatusLog->save($childStatusLog);
                    } else {
                        $childStatusLog = $this->DocStatusLog->newEntity([
                            'doc_master_id' => $child_doc_master['id'],
                            'doc_revision_id' => $child_doc_master['doc_revisions'][0]['id'],
                            'doc_status_master_id' => $statusId,
                            'start_date' => new FrozenTime(date($datetime)),
                            'end_date' => new FrozenTime(date($datetime)),
                            'step_completed' => $doc_step_completed,
                            'comments' => "Document Training Assign",
                            'next_action_by' => '', 
                            'action_by' => $this->Authentication->getIdentity('User')->id,
                            'action_taken' => $action_taken,
                            'doc_data' => ''
                        ]);
                        $childStatusLog = $this->DocStatusLog->save($childStatusLog);
                    }
                    // debug($docStatusLog);
                    // debug($child_doc_master);
                }
            }
                // 



                foreach ($result as $traningKey => $traningValue) {
                    $training_compliance_id = $traningValue['id'];
                    //if(isset($saveData['saveOnly'])){
                    if ($status != '') {
                        $status_log = $this->TrainingComplianceLog->newEntity([
                            'training_compliance_id' => $training_compliance_id,
                            'status_id' => 1,
                            'start_date' => new FrozenTime(date($datetime)),
                            'end_date' => new FrozenTime(date($datetime)),
                            'action_taken' => 'Submit',
                            'step_completed' => $step_completed,
                            'status_change_by' => $this->Authentication->getIdentity('User')->id,
                            'comments' => $comment
                        ]);
                        $this->TrainingComplianceLog->save($status_log);
                    }
                    if ($status == 3) {
                        $this->Common->updateTrainingRoleMap($customer_id,$customer_locations_id,'completed_by', $training_id);
                    }
                    else{
                        $this->Common->updateTrainingRoleMap($customer_id, $customer_locations_id,'applicable_for', $training_id);
                    }
                    if ($status == 1 || $status == 2 || $status == 3 || $status == 4) {
                        $title = 'New Training Compliance added';
                        $comments = "New training compliance added by authority.";
                        if ($status == 3) {
                            $title = 'New Training Compliance Added And Approved';
                            $comments = "New training compliance added and approved by authority.";
                        }
                        if ($status == 4) {
                            $title = 'New Training Compliance Added And Rejected';
                            $comments = "New training compliance added and rejected by authority.";
                        }
                      
                        //if($status == 3){
                            $trainer = $saveData['instructor'];
                            $this->selectdDocEmailAndNotification($docId,'assign_training_instructor',$statusId,$trainer,$saveData['from_date']);
                            $this->docEmailAndNotification($docId,$saveData['saveApprove'],$statusId,$saveData['from_date']);
                       //}
                    }
                    //}
                    $this->Flash->success(__('The training compliance has been saved.'));

                    return $this->redirect(["plugin" => false, 'controller' => 'doc-master', 'action' => 'index']);
                }
            }

            $this->Flash->error(__('The training compliance could not be saved. Please, try again.'));
        }
        }
        $employeesResult = $this->TrainingCompliance->Users->find('all', ['contain' => ['CustomerRoles']])->where(['Users.customer_id' => $session['customer_id']]);
        $employees = $employeesResult->map(function ($value, $key) {
            return [
                'text' => $value->emp_code . ' | ' . $value->first_name . ' ' . $value->last_name,
                'value' => $value->id,
                'data-dept' => $value->department_id,
                'data-role' => isset($value->employee_roles[0]['role_id']) ? $value->employee_roles[0]['role_id'] : ''
            ];
        })->toArray();
        $condition['AND'] = ['training_for_model_name' => "DocMaster",'training_for_model_id' => $docId];
        $trainingsResult = $this->TrainingCompliance->TrainingMaster->find('all')->where(['TrainingMaster.customer_id' => $customer_id,'TrainingMaster.customer_location_id' => $customer_locations_id,$condition]);
        $parentId=null;
        if(isset($docMaster->doc_master_id))
        {
            $getParentTrainingsResult = $this->TrainingCompliance->TrainingMaster->find('all')->where(['TrainingMaster.customer_id' => $customer_id,'TrainingMaster.customer_location_id' => $customer_locations_id,'training_for_model_name' => "DocMaster",'training_for_model_id' => $docMaster->doc_master_id])->last();
            $parentId = $getParentTrainingsResult->id;
        }
        
        
        if(empty($trainingsResult->toArray())){ 
            $trainingMaster = $this->TrainingMaster->newEmptyEntity();
            $newtrainingMaster=[
                'customer_id'=>$customer_id,
                'training_name'=>'Traning For - '.$docMaster['doc_no'].'-'.$docMaster['title'],
                'training_for_model_name'=>"DocMaster",
                'training_for_model_id' => $docId,
                'customer_location_id'=>$customer_locations_id,
                'type'=>'1',
                'evaluation_type'=>'3',
                'parent_training_id'=>$parentId,
            ];
            $trainingMaster = $this->TrainingMaster->patchEntity($trainingMaster, $newtrainingMaster);
        
            $this->TrainingMaster->save($trainingMaster); //debug($trainingMaster);
            $trainingsResult = $this->TrainingCompliance->TrainingMaster->find('all')->where(['TrainingMaster.customer_id' => $customer_id,'TrainingMaster.customer_location_id' => $customer_locations_id,$condition]);
         }
         $training_master = $this->TrainingMaster->find('all',['contain'=>['TrainingSections']])->where(['TrainingMaster.customer_id' => $session['customer_id'],'training_for_model_name'=>'DocMaster','training_for_model_id'=>$docId])->last();
         //debug($trainingsResult->toArray());die;
        $trainings = $trainingsResult->map(function ($value, $key) {
            return [
                'text' => $value->training_name,
                'value' => $value->id,
//                 'data-duedate' => $value->fdue_date,
//                 'passing-score' => $value->passing_score 
            ];
        })->toArray();


        $lastStatusLog = $this->DocStatusLog->find('all', ['conditions' => ['doc_master_id' => $docId]])->last();
        $currentLog = [];
        $preLog = [];
        $docRevision = $docMaster['doc_revisions'][0];
        if (!empty($docRevision)) {
            if ($statusId != '' && $docRevision['id'] != '' && $docId != '') {
                $currentLog = $this->DocStatusLog->find('all', ['conditions' => ['doc_master_id' => $docId, 'doc_revision_id' => $docRevision['id'], 'doc_status_master_id' => $statusId, "DocStatusLog.action_taken != 'Reject'"]])->last();
            }
            if ($prevStatusId != '' && $docRevision['id'] != '' && $docId != '') {
                $preLog = $this->DocStatusLog->find('all', ['conditions' => ['doc_master_id' => $docId, 'doc_revision_id' => $docRevision['id'], 'doc_status_master_id' => $prevStatusId, "DocStatusLog.action_taken != 'Reject'"]])->last();
            }
        }
        $type=Configure::read('trainingtype');
        $evaluationtype=Configure::read('evaluationtype');
        $frequency=Configure::read('trainingfrequency');
        $this->loadModel('SectionMaster');
        $section = $this->SectionMaster->find('list', ['keyField' => 'id','valueField' => 'name'])->where(['SectionMaster.customer_id'=>$customer_id,'SectionMaster.customer_location_id'=>$session['base_location_id'],'SectionMaster.status'=>1])->toArray();
        $this->loadModel('FunctionalRoles');
        $functionalRoles=$this->FunctionalRoles->find('list',['keyfield'=>'id','valueField'=>'role_name'])->where(['FunctionalRoles.customer_id'=>$customer_id])->toArray();
     
        $instructor = $this->Common->getDeptUsers(['Users.active' => 1, 'Users.customer_id' => $docMaster['customer_id'],'Users.base_location_id' => $docMaster['customer_locations_id'], 'Users.departments_id' => $docMaster['departments_id']]);
        $this->set(compact('transPass','trainingCompliance', 'trainings', 'depts', 'funcRoles', 'docMaster', 'instructor', 'lastStatusLog', 'currentLog', 'preLog','statusId','type','frequency','evaluationtype','section','functionalRoles','loggedUserId','training_master','nextStatusId','prevStatusId'));
    }

    public function pendingEmployees()
    {
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod("post");
        $this->loadComponent('Common');
        $this->loadModel('Users');
        $customer_id = $this->request->getSession()->read('Auth')['customer_id'];
        $customer_location_id = $this->request->getSession()->read('Auth')['base_location_id'];
        $depts = explode(',', $_REQUEST['dept']);
        $roles = explode(',', $_REQUEST['funcrole']);
        if (isset($_REQUEST['training_id'])) {
            $training_id =  $_REQUEST['training_id'];
        }
        
        $condition = array();
        if (!empty($depts && $depts[0] != '')) {
            $condition['AND'][] = ['Users.departments_id IN' => $depts];
        }
        if (!empty($roles) && $roles[0] != '') {
            $condition['AND'][] = ['Users.functional_role_id IN' => $roles];
        }
        $conditions=[];        
        if (!empty($training_id) && $training_id != '') {
            $conditions['AND'][] = ['training_id' => $training_id];
        }

        $training_done_emp = $this->TrainingCompliance->find('all', ['contain'=>["TrainingBatch"=>["conditions"=>['TrainingBatch.status !='=>0]]],"conditions"=>['TrainingCompliance.status !='=>0,$conditions]])
        ->toList();
        // debug($training_done_emp);
        foreach($training_done_emp as $key=>$emp_id){
            // if($emp_id->training_batch !=null ){
              $emp [] = $emp_id['user_id'];
            // }
        }
        if (!empty($emp) && $emp[0] != '') {
            $condition['AND'][] = ["Users.id NOT IN"=>$emp];
        }
        
        
       
        $employeesResult = $this->Users->find('all', ['contain' => ['Departments']])
        ->where(['Users.customer_id' => $customer_id, 'Users.base_location_id'=>$customer_location_id,$condition])->toArray();
       
        $employees = array();
        foreach($employeesResult as $key => $value){
            $employees[]=[
                'text' => $value->username . ' | ' . $value->first_name . ' ' . $value->last_name . ' | ' . $value->department->department,
                'value' => $value->id,
                'dept' => $value->department->department,
                'role' => isset($value->user_roles) ? $value->user_roles : ''
            ];
        }
        $this->set(compact('employees'));
        $this->set("_serialize", ['employees']);
        if ($this->request->is("ajax")) {
            $this->viewBuilder()
                ->setOption('serialize', $this->viewBuilder()->getVar("_serialize"))
                ->setOption('jsonOptions', JSON_FORCE_OBJECT);
        }
    }

   public function editAssignTrainingToDocument($docId = null, $revId = null, $batchId = null)
    {
        $this->loadModel('Tc.TrainingBatch');
        $this->loadComponent('Common');
        $this->loadModel("Departments");
        $this->loadModel("Users");
        $this->loadModel("CustomerRoles");
        $this->loadModel('DocMaster');
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel("EmployeeMaster");
        $this->loadModel('DocStatusLog');
        $this->loadModel('DocStatusMaster');
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingBatch = $this->TrainingBatch->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        if (!$this->Authorization->can($trainingCompliance, 'assignTrainingToDocument')) {
            $this->Flash->error(__('You are not authorized to access this page!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $docId = ($docId == null) ? null : decryptVal($docId);
        $revId = ($revId == null) ? null : decryptVal($revId);
        $batchId = ($revId == null) ? null : decryptVal($batchId);
          
        $customer_id = $this->request->getSession()->read('Auth')['customer_id'];
        $location_id = $this->request->getSession()->read('Auth')['base_location_id'];
        $loggedUserEmailId = $this->request->getSession()->read('Auth')['email'];
        $plugin=$this->request->getParam('plugin');
        $controller=$this->request->getParam('controller');
        $session = $this->request->getSession()->read('Auth');
        $loggedUserId = $this->request->getSession()->read('Auth')['id'];

        $depts = $this->Departments->find('list', ['keyField' => 'id', 'valueField' => 'department'])->where(["Departments.customer_id" => $customer_id])->toArray();
        $funcRoles = $this->CustomerRoles->find('list', ['keyField' => 'id', 'valueField' => 'roles_name'])->where(["CustomerRoles.customer_id" => $customer_id])->toArray();

        $docMaster = $this->DocMaster->find('all')
            ->contain([
                'DocRevisions','DocChildMaster'=>['DocMaster'=>['DocRevisions'],'conditions'=>['doc_type'=>'sub_document']]
            ])
            ->where(['id' => $docId])->first()->toArray();

        $statusId = $docMaster['doc_status_master_id'];
        $trainingBatch = $this->TrainingBatch->get($batchId, [
            'contain' => ['TrainingMaster'=>['TrainingSections'], 'TrainingSessionSlots'=>['TrainingSlotAttendance'=>["Users"=>["fields"=>["userfullname"]]]],'TrainingCompliance' => ['Users' => ['Departments']],'TrainingStatusLog'=>["sort" => ["TrainingStatusLog.id" => "Desc"]]]],
        );
          
        $datetime  = CustomerCache::read("datetimeformat");
        if($datetime==null){
            $datetime  = CustomerCache::read("datetimeformat",null,0);
        }
        if ($datetime == null){
            $datetime  = Configure::read("datetimeformat");
        }
        $doc_status_master = $this->DocStatusMaster->find('list', ['keyValue' => 'id', 'valueField' => 'form_name'])->toArray();
        $count = count($doc_status_master);
        $docMasterController = new DocMasterController();
        $lastStepId = $docMasterController->getLastStepId('Doc');
        
        if ($this->request->is(['patch', 'post', 'put'])) {
            $saveData = $this->request->getData();
            $saveData['training_master']['selected_department'] = json_encode($saveData['department_id']);
            $saveData['training_master']['selected_roles'] = json_encode($saveData['functional_role_id']);
           
            $customer_id = $saveData['customer_id'];
            $training_id = $saveData['training_id'];
            $employee_id = isset($saveData['employees'])?$saveData['employees']:'';
            $batch_no = $saveData['batch_no'];
            $batch_data = $this->TrainingBatch->find('all')
            ->where(['batch_no'=> $batch_no,'training_master_id'=>$training_id])->toArray();
            
            if (isset($saveData['slot_deleted'])) {
                $deleted_ids = explode(',', $saveData['slot_deleted']);
                $this->loadModel("TrainingSessionSlots");
                $this->TrainingSessionSlots->deleteAll(['id IN'=>$deleted_ids]);
            }
           
            if(count($batch_data)>1){
                $this->Flash->error(__('The training compliance could not be saved Training Session No. already Exists. Please, try again.'));
                //return $this->redirect(['action' => 'assignTrainingIndex',encryptVal($docId)]);
            }else
            {
                $functional_role_id = !empty($saveData['functional_role_id']) ? $saveData['functional_role_id'] : [];
                foreach($functional_role_id as $k=>$role){
                    $saveData['training_master']['training_roles'][$k]['functional_role_id']=(int)$role;
                }
                $training_master = isset($saveData['training_master']) ? $saveData['training_master'] : '';
                $m_array =  array_merge($training_master,
                ['training_for_model_name' => "DocMaster",  'training_for_model_id' => $docId,'id'=>$training_id]);
                $saveData['training_master'] = $m_array;
                if(isset($saveData['training_master']['training_sections']))
                {
                    $saveData['training_master']['training_sections'] =  $saveData['training_sections'];
                }
                
                $trainingDetails = $this->TrainingMaster->get($training_id,['contain'=>['TrainingSections']]);
                $trainingDetails = $this->TrainingMaster->patchEntity($trainingDetails, $saveData['training_master'],['associated'=>['TrainingRoles','TrainingSections']]);
                // debug($trainingDetails);exit;
                $trainingDetails = $this->TrainingMaster->save($trainingDetails);
            }
            if (isset($saveData['training_compliance_attachment'])) {
                foreach ($saveData['training_compliance_attachment'] as $k => $file) {
                    $attachment = $file["file_name"];
                    if ($attachment->getError() == 0) {
                        $filename = date("YmdHis") . $attachment->getClientFilename();
                        $tmp_name = $attachment->getStream()->getMetadata('uri');
                        QMSFile::moveUploadedFile($tmp_name, "training_compliance" . DS . $filename, $customer_id);
                        $saveData["training_compliance_attachment"][$k]['file_name'] = $filename;
                    } else {
                        unset($saveData['training_compliance_attachment'][$k]);
                    }
                }
            } else {
                unset($saveData["training_compliance_attachment"]);
            }
            $comment = isset($saveData['comments']) ? $saveData['comments'] : '';
            $status = '';
            //start->commented on 02-10-2021
            /* if(isset($saveData['saveOnly'])){
             if($session['groups_id'] == 3){
             $status=1;
             }else{
             $status=2;
             }
             
             }
             if(isset($saveData['saveSendForApproval'])){
             $status=2;
             } */
            //End
            if(isset($saveData['training_session_slots']))
            {
                foreach ($saveData['training_session_slots'] as $k=>$editData)
                {
                    $slotDate = new FrozenDate($editData['slot_date']);
                    $saveData['training_session_slots'][$k]['slot_date'] = $slotDate;
                }
                
            }
            

            if (isset($saveData['saveOnly'])) {
                $status = 0;
                $step_completed = 0;
            }

            /*------------*/
            if (isset($saveData['saveApprove'])) {
                $status = 1;
                $step_completed = 1;
            }

            //Create Batch 
            if ($saveData['id'] != '') {
                // $TrainingBatch = $this->TrainingBatch->get($saveData['id']);
            } else {
                $TrainingBatch = $this->TrainingBatch->newEmptyEntity();
            }
           
            $saveData['training_master_id'] = $saveData['training_id'];
            $saveData['training_status_log'][0]['training_master_id'] = $saveData['training_id'];
            $saveData['training_status_log'][0]['step_completed'] = $step_completed;
            $saveData['training_status_log'][0]['is_complete'] = $step_completed;
            $saveData['from_date'] = new FrozenDate($saveData['from_date']);
            $saveData['to_date'] = new FrozenDate($saveData['to_date']);
            $saveData['training_for_model_name'] = "DocMaster";
            $saveData['training_for_model_id'] = $docId;
            $saveData['status'] = $status;

            if($saveData['instructor_type'] == 'external'){
                $saveData['instructor_external'] = $saveData['instructor'];
            }
     
            $TrainingBatch = $this->TrainingBatch->patchEntity($trainingBatch, $saveData,['associated'=>['TrainingStatusLog','TrainingSessionSlots']]);
           // debug($TrainingBatch);exit;
           $trainingBatch = $this->TrainingBatch->save($TrainingBatch);

            $saveData['is_special'] = 0;
            $trainingComplianceData = [];
            $count = 0;
            $due_date = $trainingDetails['due_date'];
            if (isset($saveData['employees'])) {
                foreach ($saveData['employees'] as $key => $value) {
                    $count = $count + 1;
                    $id = '';
                    foreach ($TrainingBatch as $key => $arr) {
                        if ($arr['user_id'] == $value) {
                            $id =   $arr['id'];
                            break;
                        }
                    }
                    // debug($value);
                    $userData = $this->Users->find('all',['fields'=>['departments_id','functional_role_id']])->where(['id'=>$id])->first();
                 
                    if ($saveData['type'] == 2) {
                        $trainstatus = 1;
                    }
                    else {
                        $trainstatus = 0;
                    }
                    
                    $trainingComplianceData[] = [
                        
                        'id' => $id,
                        'customer_id' => $saveData['customer_id'],
                        //'employee_id' => $value,
                        'user_id' => $value,
                        'training_id' => $saveData['training_id'],
                        'training_batch_id' => $trainingBatch['id'],
                        'start_date' => new FrozenTime($saveData['from_date']),
                        'end_date' => new FrozenTime($saveData['to_date']),
                        'due_date' =>new FrozenTime($due_date),
                        'is_special' => 0,
                        'passing_score' => isset($saveData['passing_score'])?$saveData['passing_score']:0,
                        'duration' => 0,
                        'created_by' => $this->Authentication->getIdentity('User')->id,
                        'status' => $trainstatus,
                        'department_id' => isset($userData->departments_id)?$userData->departments_id:null,
                    ];
                }
     }
            
                if (isset($saveData['removeSys'])) { 
                    $this->loadModel('Tc.TrainingSections');
                    $aray= explode(',', $saveData['removeSys']);
                    $condition = array('TrainingSections.id in' => $aray);
                    $this->TrainingSections->deleteAll($condition,false);
                }
            $traningData = $this->TrainingCompliance->newEntities($trainingComplianceData);
            $trainingCompliance = $this->TrainingCompliance->patchEntities($traningData, $trainingComplianceData);
            
            if ($result = $this->TrainingCompliance->saveMany($trainingCompliance)) {
                //  Document Status Log
                
                if(!empty($saveData['employees'])){
                    foreach ($saveData['employees'] as $employee){
                        if(!empty($employee)){
                            $notificationData = [
                                'selected_users' => $employee,
                                'title' =>$trainingDetails->training_name,
                                'training_name' =>$trainingDetails->training_name,
                                'customer_id'=>$customer_id,
                                'notification_identifier'=>'add_training',
                                "action_link"=>["plugin"=>'Tc', "controller"=>"TrainingCompliance","action"=>"usertrainingindex"] // link to redirect to user.
                            ];
                            
                            $this->loadComponent('CommonMailSending');
                            $this->CommonMailSending->selected_email_details($customer_id,$location_id,$session->id,$notificationData,$loggedUserEmailId);
                            
                            $this->loadComponent('QmsNotification');
                            $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$location_id,$session->id,$notificationData,$session->$location_id);
                            
                        }
                    }
                }

                $uncomplete = $this->TrainingBatch->find('all', ["contain" => ["TrainingMaster"], "conditions" => ['TrainingBatch.training_for_model_name' => 'DocMaster', 'TrainingBatch.training_for_model_id' => $docId, 'TrainingBatch.status IN' => [0]]])->toArray();
           
                if (empty($uncomplete)) {
                    $doc_step_completed = 1;
                    $action_taken = "Approve";
                } else {
                    $doc_step_completed = 0;
                    $action_taken = "Submit";
                }
               
                $log_data = $saveData['doc_status_log'];
                if ($log_data != '') {      //debug($doc_step_completed);die;
                    $this->loadModel('DocStatusLog');
                    $next_action_by = $log_data[0]['next_action_by'];
                    $log_id = $log_data[0]['id'];
                    if ($log_id != '') {
                        $docStatusLog = $this->DocStatusLog->get($log_id);
                        $statusLogData = [
                            'step_completed' => $doc_step_completed,
                            'comments' => "Assign Training",
                            'next_action_by' => $next_action_by,
                            'action_by' => $this->Authentication->getIdentity('User')->id,
                            'action_taken' => $action_taken,
                        ];
                      
                        $docStatusLog = $this->DocStatusLog->patchEntity($docStatusLog, $statusLogData);
                        $docStatusLog = $this->DocStatusLog->save($docStatusLog);
                    }
                }
                
       
                $this->loadModel('DocChildMaster');
                $this->loadModel('DocStatusLog');

                $child_doc = $this->DocChildMaster->find('all')
                ->contain(['DocChildDetail'=>['DocRevisions']])
                ->where(['DocChildMaster.doc_master_id' => $docId,'doc_type'=>'sub_document','DocChildDetail.doc_status_master_id <'=>$lastStepId])->toArray();
          
                foreach($child_doc as $key=>$child){
                    $child_doc_master = $child['doc_child_detail'];
                    $this->DocMaster->updateAll(['doc_status_master_id'=>$statusId],['id'=> $child_doc_master['id']]);
                    $childStatus =  $this->DocStatusLog->find()->where(['doc_master_id'=>$child_doc_master['id'],'doc_status_master_id'=>$statusId])->last();
                    if (!empty($childStatus)) {
                        $childStatusLog = $this->DocStatusLog->get($childStatus['id']);
                        $statusLogData = [
                            'step_completed' => $doc_step_completed,
                            'comments' => "Document Training Assign",
                            'next_action_by' => '',
                            'action_by' => $this->Authentication->getIdentity('User')->id,
                            'action_taken' => $action_taken,
                        ];
                        $childStatusLog = $this->DocStatusLog->patchEntity($childStatusLog, $statusLogData);
                        $childStatusLog = $this->DocStatusLog->save($childStatusLog);
                    } else {
                        $childStatusLog = $this->DocStatusLog->newEntity([
                            'doc_master_id' => $child_doc_master['id'],
                            'doc_revision_id' => $child_doc_master['doc_revisions'][0]['id'],
                            'doc_status_master_id' => $statusId,
                            'start_date' => new FrozenTime(date($datetime)),
                            'end_date' => new FrozenTime(date($datetime)),
                            'step_completed' => $doc_step_completed,
                            'comments' => "Document Training Assign",
                            'next_action_by' => '', 
                            'action_by' => $this->Authentication->getIdentity('User')->id,
                            'action_taken' => $action_taken,
                            'doc_data' => ''
                        ]);
                        $childStatusLog = $this->DocStatusLog->save($childStatusLog);
                    }
                  }

                // 

                foreach ($result as $traningKey => $traningValue) {
                    $training_compliance_id = $traningValue['id'];
                    //if(isset($saveData['saveOnly'])){
                    if ($status != '') {
                        $this->loadModel('Tc.TrainingComplianceLog');
                        $status_log = $this->TrainingComplianceLog->newEntity([
                            'training_compliance_id' => $training_compliance_id,
                            'status_id' => 1,
                            'start_date' => new FrozenTime(date($datetime)),
                            'end_date' => new FrozenTime(date($datetime)),
                            'action_taken' => 'Submit',
                            'step_completed' => $step_completed,
                            'status_change_by' => $this->Authentication->getIdentity('User')->id,
                            'comments' => $comment
                        ]);
                        $this->TrainingComplianceLog->save($status_log);
                    }
                    if ($status == 3) {

                        $this->Common->updateTrainingRoleMap($customer_id,1,'completed_by', $training_id);
                    }
                    if ($status == 1 || $status == 2 || $status == 3 || $status == 4) {
                        $title = 'New Training Compliance added';
                        $comments = "New training compliance added by authority.";
                        if ($status == 3) {
                            $title = 'New Training Compliance Added And Approved';
                            $comments = "New training compliance added and approved by authority.";
                        }
                        if ($status == 4) {
                            $title = 'New Training Compliance Added And Rejected';
                            $comments = "New training compliance added and rejected by authority.";
                        }
                        // foreach ($employee_id as $key => $employeeId) {

                        //     if ($employee_id != '') {
                        //         $employee = $this->Users->get($employeeId);
                        //         if (!empty($employee)) {
                        //             if (!empty($employee['user_id'])) {
                        //                 $notification = new SimpleNotification([
                        //                     "notification_inbox_data" => [
                        //                         "customer_id" => $customer_id,
                        //                         "created_by" => $session['id'],
                        //                         "user_type" => "Users",   // accepts User|Groups|Departments
                        //                         "user_reference_id" => $employee['user_id'], // relavtive id
                        //                         "title" => $title, // title of notification
                        //                         "comments" => $comments, // content of notification
                        //                         "plugin_name" => 'Tc', // for which plugin_name you are highlighting. if required
                        //                         "model_reference_name" => "TrainingCompliance", // for which plugin reference name   if required
                        //                         "model_reference_id" => $training_compliance_id, //   if required
                        //                         "action_link" => ["plugin" => 'Tc', "controller" => "TrainingCompliance", "action" => "view", $training_compliance_id] // link to redirect to user.
                        //                     ],
                        //                 ]);
                        //                 $notification->send();
                        //             }
                        //         }
                        //     }
                        // }

                        
                            if($status == 3){
                                $trainer = $saveData['instructor'];
                                $this->selectdDocEmailAndNotification($docId,'assign_training_instructor',$statusId,$trainer,$saveData['from_date']);
                                $this->docEmailAndNotification($docId,$saveData['saveApprove'],$statusId,$saveData['from_date']);
                       }
                      
                    }
                    //}
                    $this->Flash->success(__('The training compliance has been saved.'));

                    return $this->redirect(["plugin" => false, 'controller' => 'doc-master', 'action' => 'index']);
                }
            }
            $this->Flash->error(__('The training compliance could not be saved. Please, try again.'));
        }
        
        $employeesResult = $this->Users->find('all', ['contain' => ['CustomerRoles']])->where(['Users.customer_id' => $session['customer_id']]);
        $employees = $employeesResult->map(function ($value, $key) {
            return [
                'text' => $value->emp_code . ' | ' . $value->first_name . ' ' . $value->last_name,
                'value' => $value->id,
                'data-dept' => $value->department_id,
                'data-role' => isset($value->employee_roles[0]['role_id']) ? $value->employee_roles[0]['role_id'] : ''
            ];
        })->toArray();
        $trainingsResult = $this->TrainingCompliance->TrainingMaster->find('all')->where(['TrainingMaster.customer_id' => $session['customer_id'],'id'=>$trainingBatch['training_master_id']]);

        $trainings = $trainingsResult->map(function ($value, $key) {
            return [
                'text' => $value->training_name,
                'value' => $value->id,
                'data-duedate' => $value->fdue_date,
                'passing-score' => $value->passing_score
            ];
        })->toArray();
        $currentLog = [];
        $preLog = [];
        $lastStatusLog = $this->DocStatusLog->find('all', ['conditions' => ['doc_master_id' => $docId]])->last();
        $currentLog = [];
        $preLog = [];
        $docRevision = $docMaster['doc_revisions'][0];

        if (!empty($docRevision)) {
            if ($statusId != '' && $docRevision['id'] != '' && $docId != '') {
                $currentLog = $this->DocStatusLog->find('all', ['conditions' => ['doc_master_id' => $docId, 'doc_revision_id' => $docRevision['id'], 'doc_status_master_id' => $statusId, "DocStatusLog.action_taken != 'Reject'"]])->last();
            }
            
        }

        $type=Configure::read('trainingtype');
        $evaluationtype=Configure::read('evaluationtype');
        $frequency=Configure::read('trainingfrequency');
        $this->loadModel('SectionMaster');
        $section = $this->SectionMaster->find('list', ['keyField' => 'id','valueField' => 'name'])->where(['SectionMaster.customer_id'=>$customer_id,'SectionMaster.customer_location_id'=>$session['base_location_id']])->toArray();
        $this->loadModel('FunctionalRoles');
        $functionalRoles=$this->FunctionalRoles->find('list',['keyfield'=>'id','valueField'=>'role_name'])->where(['FunctionalRoles.customer_id'=>$customer_id])->toArray();
        $training_status_log = $trainingBatch['training_status_log'];
        
        $instructor = $this->Common->getDeptUsers(['Users.active' => 1, 'Users.customer_id' => $docMaster['customer_id'],'Users.base_location_id' => $docMaster['customer_locations_id'], 'Users.departments_id' => $docMaster['departments_id']]);
        $this->set(compact('trainingBatch', 'trainings', 'depts', 'funcRoles', 'docMaster', 'instructor', 'currentLog', 'lastStatusLog','statusId','type','evaluationtype','frequency','section','functionalRoles','loggedUserId','training_status_log'));
    }

    public function assignTrainingView($docId = null, $revId = null, $batchId = null)
    {
        //$employee_id=($employee_id==null)?null:decryptVal($employee_id);
        $this->loadModel('Tc.TrainingBatch');
        $this->loadComponent('Common');
        $this->loadModel("Departments");
        $this->loadModel("CustomerRoles");
        $this->loadModel('DocMaster');

        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingBatch = $this->TrainingBatch->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        if (!$this->Authorization->can($trainingCompliance, 'assignTrainingToDocument')) {
            $this->Flash->error(__('You are not authorized to access this page!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $docId = ($docId == null) ? null : decryptVal($docId);
        $revId = ($revId == null) ? null : decryptVal($revId);
        $batchId = ($revId == null) ? null : decryptVal($batchId);

        $customer_id = $this->request->getSession()->read('Auth')['customer_id'];
        $session = $this->request->getSession()->read('Auth');

        //$this->Common->updateTrainingRoleMap($session['customer_id'],'applicable_for');
        //$this->Common->updateTrainingRoleMap($session['customer_id'],'completed_by',3);
        $depts = $this->Departments->find('list', ['keyField' => 'id', 'valueField' => 'department'])->where(["Departments.customer_id" => $customer_id])->toArray();
        $funcRoles = $this->CustomerRoles->find('list', ['keyField' => 'id', 'valueField' => 'role_name'])->where(["CustomerRoles.customer_id" => $customer_id])->toArray();

        $docMaster = $this->DocMaster->find('all')
            ->contain([
                'DocRevisions' => ["fields" => ["DocRevisions.id", "DocRevisions.doc_master_id"]]
            ])
            ->where(['id' => $docId])->first()->toArray();

        $trainingBatch = $this->TrainingBatch->get($batchId, [
            'contain' => ['TrainingMaster','Instructor', 'TrainingCompliance' => ['Assigned'],],
        ]);

        $employeesResult = $this->TrainingCompliance->Assigned->find('all', ['contain' => ['CustomerRoles']])->where(['Assigned.customer_id' => $session['customer_id']]);
        $employees = $employeesResult->map(function ($value, $key) {
            return [
                'text' => $value->emp_code . ' | ' . $value->first_name . ' ' . $value->last_name,
                'value' => $value->id,
                'data-dept' => $value->department_id,
                'data-role' => isset($value->employee_roles[0]['role_id']) ? $value->employee_roles[0]['role_id'] : ''
            ];
        })->toArray();
        $trainingsResult = $this->TrainingCompliance->TrainingMaster->find('all')->where(['TrainingMaster.customer_id' => $session['customer_id']]);

        $trainings = $trainingsResult->map(function ($value, $key) {
            return [
                'text' => $value->training_name,
                'value' => $value->id,
                'data-duedate' => $value->fdue_date,
                'passing-score' => $value->passing_score
            ];
        })->toArray();

        $this->loadComponent('Common');
        $instructor = $this->Common->getDeptUsers(['Users.active' => 1, 'Users.customer_id' => $docMaster['customer_id'], 'Users.departments_id' => $docMaster['departments_id']]);
        $this->set(compact('trainingBatch', 'trainings', 'depts', 'funcRoles', 'docMaster', 'instructor'));
    }


    public function trainingEvidence($docId = null, $nextStatusId = null, $prevStatusId = null,$statusId = null)
    {
        
        //$employee_id=($employee_id==null)?null:decryptVal($employee_id);
        $this->loadModel('Tc.TrainingBatch');
        $this->loadModel('Tc.TrainingCompliance');
        $this->loadModel('Tc.TrainingMaster');
        $this->loadComponent('Common');
        $this->loadModel('DocMaster');
        $this->loadModel('DocStatusLog');
        $this->loadModel('DocStatusMaster');
        $this->loadComponent('WiseWorks');
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingBatch = $this->TrainingBatch->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        if (!$this->Authorization->can($trainingCompliance, 'trainingEvidence')) {
            $this->Flash->error(__('You are not authorized to access this page!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $docId = ($docId == null) ? null : decryptVal($docId);
        $prevStatusId = ($prevStatusId == null) ? null : decryptVal($prevStatusId);
        $nextStatusId = ($nextStatusId == null) ? null : decryptVal($nextStatusId);
        $statusId = ($statusId == null) ? null : decryptVal($statusId);

        $customer_id = $this->request->getSession()->read('Auth')['customer_id'];
        $location_id = $this->request->getSession()->read('Auth')['base_location_id'];
        $session = $this->request->getSession()->read('Auth');

        $loggedUser_id = $this->request->getSession()->read('Auth')['id'];        
        $AccessiblePlugins = Configure::read('AccessiblePlugins');
        $isDocSeperate = 'N';
        if (isset($AccessiblePlugins) && $AccessiblePlugins !='') {
            $isTrainingPresent = in_array('Training Master', $AccessiblePlugins);
            if (!$isTrainingPresent) {
                $isDocSeperate = 'Y';
            }            
        }
     
        $isTransPassword = CustomerCache::read("transactional_password_required");
        if ($isTransPassword == 'Y') {
            $transPass  = 1;
        }
        else {
            $transPass = 0;
        }
        
        $docMaster = $this->DocMaster->find('all')
        ->contain([
            'DocRevisions' => ["fields" => ["DocRevisions.id", "DocRevisions.doc_master_id"]],
            'Customer', 'DocFormatsMaster', 'CustomerLocations' => ['Countries', 'State', 'City'],
            'Departments', 'DocDistributionList' => ['Departments'],
            'DocTrainingEvidence',
            'DocStatusLog' => [
                "ActionByUser" => ["fields" => ["userfullname"]],
                "NextActionByUser" => ["fields" => ["userfullname"]]
            ],
        ])
        ->where(['DocMaster.id' => $docId])->first()->toArray();
        
        $revId = $docMaster['doc_revisions'][0]['id'];
        $docRevision = $docMaster['doc_revisions'][0];
        $datetimeformat  = CustomerCache::read("datetimeformat");
        if($datetimeformat==null){
            $datetimeformat  = CustomerCache::read("datetimeformat",null,0);
        }
        if ($datetimeformat == null){
            $datetimeformat  = Configure::read("datetimeformat");
        }
        $doc_status_master = $this->DocStatusMaster->find('list', ['keyValue' => 'id', 'valueField' => 'form_name'])->toArray();
        $count = count($doc_status_master);
        
        if ($this->request->is(['patch', 'post', 'put'])) {

            $requestData = $this->request->getData(); //debug($requestData);die;
            // $customer_id=$requestData['customer_id'];
            $doc_training_evidence = isset($requestData['doc_training_evidence'])?$requestData['doc_training_evidence']:"";
            $log_data = $requestData['doc_status_log'];
            $training_batch = isset($requestData['training_batch'])?$requestData['training_batch']:'';
            $requestData['id'] = $docId;
            $docMaster = $this->DocMaster->get($docId, ["contain" => [
                'DocRevisions','DocChildMaster'=>['DocMaster'=>['DocRevisions'],'conditions'=>['doc_type'=>'sub_document']]
            ]]);
            // $comment = $requestData['doc_status_log'][0]['next_action_comments'];
            $plugin = NULL;
            $this->loadComponent('Common');
            $this->Common->close_notification($plugin,'TrainingCompliance',$docId);
            
            unset($requestData['doc_training_evidence']);
            unset($requestData['doc_status_log']);
            $docMaster = $this->DocMaster->patchEntity($docMaster, $requestData);
            $docMaster['doc_status_master_id'] = $statusId;
            $result = $this->DocMaster->save($docMaster);
            if ($result) {
                $id = $result['id'];
                $revision_id = $result['doc_revisions'][0]['id'];
                $revision_no = $result['doc_revisions'][0]['revision_no'];
                $step_complete = 0;
                $log_id = '';
                $loggedUserDeptId = $this->request->getSession()->read('Auth')['departments_id'];
                $customer_id = $this->request->getSession()->read('Auth')['customer_id'];
                if (isset($requestData['saveForApprove'])) {
                    $step_complete = 1;
                }
                if (isset($doc_training_evidence) && $doc_training_evidence !='') {
                    
                    $doc_attachment=[];
                    foreach($doc_training_evidence as $j=>$Docttachments){
                        if(isset($Docttachments["file_name"]) && $Docttachments["file_name"]!=""){
                            $attachments=$Docttachments['file_name'];
                            if($attachments->getError()==0){
                                $filename=date("YmdHis").$attachments->getClientFilename();
                                $tmp_name=$attachments->getStream()->getMetadata('uri');
                                $upload=QMSFile::moveUploadedFile($tmp_name,"doc_training_evidence/".$id.DS.$filename,$customer_id);
                                $doc_attachment[$j]['customer_id']=$customer_id;
                                $doc_attachment[$j]['customer_locations_id']=$location_id;
                                $doc_attachment[$j]['file_name']=$filename;
                                $doc_attachment[$j]['doc_master_id']=$id;
                                $doc_attachment[$j]['created_by']=$loggedUser_id;
                                $doc_attachment[$j]['modified_by']=$loggedUser_id;
                                
                            }else{
                                unset($doc_attachment[$j]['file_name']);
                            }
                        }
                    }
                     if(isset($doc_attachment) && count($doc_attachment) > 0 ){
                        $this->loadModel('DocTrainingEvidence');
                        $docAttachmentData= $this->DocTrainingEvidence->newEntities($doc_attachment);
                        $this->DocTrainingEvidence->saveMany($docAttachmentData);
                        
                    }
                    $this->loadModel('PluginsModule');                   
                    $nextnotificaion = $this->PluginsModule->find('all')->where(['plugin_type'=>'notification','plugin'=>'Doc','modal_reference_id'=>$nextStatusId])->toArray();
                    $sendmethod = !empty($nextnotificaion)? $nextnotificaion[0]->method :'';

                    $this->docEmailAndNotification($id,'saveForApprove',$statusId,null,null,$this->Authentication->getIdentity('User')->id,$sendmethod);
                }
                /* foreach ($training_batch as $key => $evidence) {
                    $attachment = $evidence["training_evidence"];

                     //debug($attachment);die;
                    if (isset($attachment)) {

                    if ($attachment->getError() == 0) {
                        $filename = $attachment->getClientFilename();
                        $tmp_name = $attachment->getStream()->getMetadata('uri');
                        $filesPathNew = "training/" . $evidence['training_master_id'] . DS . $filename;
                        $data['attachment_name'] = $filename;
                        if (QMSFile::fileExistscheck($filesPathNew, $customer_id)) {
                            QMSFile::moveUploadedFile($tmp_name, "training/" . $evidence['training_master_id'] . DS . $filename, $customer_id);
                            $trainingBatch = $this->TrainingBatch->get($evidence['id']);
                            $trainingBatch['training_evidence'] = $filename;
                            $trainingBatch['start_time'] = $evidence['start_time'];
                            $trainingBatch['end_time'] = $evidence['end_time'];
                            $trainingBatch['total_duration'] = $evidence['total_duration'];
                            $trainingBatch['status'] = 3;
                            $this->TrainingBatch->save($trainingBatch);
                        } else {
                            QMSFile::moveUploadedFile($tmp_name, "training/" . $evidence['training_master_id'] . DS . $filename, $customer_id);
                            $trainingBatch = $this->TrainingBatch->get($evidence['id']);
                            $trainingBatch['training_evidence'] = $filename;
                            $trainingBatch['start_time'] = $evidence['start_time'];
                            $trainingBatch['end_time'] = $evidence['end_time'];
                            $trainingBatch['total_duration'] = $evidence['total_duration'];
                            $trainingBatch['status'] = 3;
                            $this->TrainingBatch->save($trainingBatch);
                        }
                    }
                    }
                }
//                 debug($requestData['TrainingCompliance']);die;
//                 // update score in training complience 
//                 $trainingCompliance = $this->TrainingCompliance->patchEntities($trainingCompliance, $requestData['TrainingCompliance']);
//                 debug($trainingCompliance);die;
//                 $trainingCompliance = $this->TrainingCompliance->saveMany($trainingCompliance);

                } */
                // update score in training complience 
              /*   $trainingCompliance = $this->TrainingCompliance->patchEntities($trainingCompliance, $requestData['TrainingCompliance']);
                $trainingCompliance = $this->TrainingCompliance->saveMany($trainingCompliance); */
                
                //  Document Status Log
                if($isDocSeperate != "Y")
                {
                        //$uncomplete = $this->TrainingBatch->find('all', ["contain" => ["TrainingMaster"=>['conditions'=>['type !='=>'Elearning','evaluation_type !='=>'Online']]], "conditions" => ['TrainingBatch.training_for_model_name' => 'DocMaster', 'TrainingBatch.training_for_model_id' => $docId, 'TrainingBatch.status IN'=>[1,0]]])->toArray();
                        //$uncomplete = $this->TrainingBatch->find('all', ["contain" => ["TrainingMaster"=>['conditions'=>['type !='=>'Elearning','evaluation_type !='=>'Online']]], "conditions" => ['TrainingBatch.training_for_model_name' => 'DocMaster', 'TrainingBatch.training_for_model_id' => $docId, 'TrainingBatch.status IN'=>[1,0]]])->toArray();
                         $uncomplete = $this->TrainingCompliance->find('all', ["contain" => ["TrainingMaster","TrainingBatch"], "conditions" => ['TrainingBatch.training_for_model_name' => 'DocMaster', 'TrainingBatch.training_for_model_id' => $docId, 'TrainingCompliance.is_present IS NULL']])->toArray();
                        //debug($uncomplete);exit;
                        if (empty($uncomplete)) {
                            $doc_step_completed = 1;
                        } else {
                            $doc_step_completed = 0;
                        }
                }
                else {
                    $doc_step_completed = $step_complete;
                }
                // debug($doc_step_completed);exit;
                if ($log_data != '') {
                   
                    $next_action_by = $log_data[0]['next_action_by'];
                    $log_id = $log_data[0]['id'];
                    if ($log_id != '') {
                        $docStatusLog = $this->DocStatusLog->get($log_id);
                        $statusLogData = [
                            'step_completed' => $doc_step_completed,
                            'comments' => "Training Evidence Upload",
                            'action_by' => $this->Authentication->getIdentity('User')->id,
                            'doc_data' => ''
                        ];
                        $docStatusLog = $this->DocStatusLog->patchEntity($docStatusLog, $statusLogData);
                        $docStatusLog = $this->DocStatusLog->save($docStatusLog);
                    } else {
                        $docStatusLog = $this->DocStatusLog->newEntity([
                            'doc_master_id' => $id,
                            'doc_revision_id' => $revision_id,
                            'doc_status_master_id' => $statusId,
                            'start_date' => new FrozenTime(date($this->DateTimeFormat)),
                            'end_date' => new FrozenTime(date($this->DateTimeFormat)),
                            'step_completed' => $doc_step_completed,
                            'comments' => "Training Evidence",
                            'next_action_by' => $next_action_by,
                            'action_by' => $this->Authentication->getIdentity('User')->id,
                            'action_taken' => 'Submit',
                            'doc_data' => ''
                        ]);
                        $docStatusLog = $this->DocStatusLog->save($docStatusLog);
                        $log_id = $docStatusLog['id'];
                    }
                }
                if($doc_step_completed){
                    // debug($training_batch);die;
                     if(isset($training_batch) && $training_batch !='')
                    {
                    foreach ($training_batch as $key => $evidence) {
                        $evidence['start_time'] = isset($evidence['start_time'])?$evidence['start_time']:'';
                        $evidence['total_duration'] = isset($evidence['total_duration'])?$evidence['total_duration']:'';
                        $this->docEmailAndNotification($id,'saveForApprove',$statusId,$evidence['start_time'],$evidence['total_duration'],$this->Authentication->getIdentity('User')->id);
                    }
                    }else{
                        $this->docEmailAndNotification($id,'saveForApprove',$statusId,$this->Authentication->getIdentity('User')->id);

                    }

                }
                $this->loadModel('DocChildMaster');
                $this->loadModel('DocStatusLog');

                $child_doc = $this->DocChildMaster->find('all')
                    ->contain(['DocChildDetail'=>['DocRevisions']])
                    ->where(['DocChildMaster.doc_master_id' => $id,'doc_type'=>'sub_document','DocChildDetail.doc_status_master_id <'=>$count])->toArray();
              
                foreach($child_doc as $key=>$child){
                    $child_doc_master = $child['doc_child_detail'];
                    $this->DocMaster->updateAll(['doc_status_master_id'=>$statusId],['id'=> $child_doc_master['id']]);
                    $childStatus =  $this->DocStatusLog->find()->where(['doc_master_id'=>$child_doc_master['id'],'doc_status_master_id'=>$statusId])->last();
                    if (!empty($childStatus)) {
                        $childStatusLog = $this->DocStatusLog->get($childStatus['id']);
                        $statusLogData = [
                            'step_completed' => $doc_step_completed,
                            'comments' => "Document Training Assign",
                            'next_action_by' => '',
                            'action_by' => $this->Authentication->getIdentity('User')->id,
                            'action_taken' => 'Submit',
                        ];
                        $childStatusLog = $this->DocStatusLog->patchEntity($childStatusLog, $statusLogData);
                        $childStatusLog = $this->DocStatusLog->save($childStatusLog);
                    } else {
                        $childStatusLog = $this->DocStatusLog->newEntity([
                            'doc_master_id' => $child_doc_master['id'],
                            'doc_revision_id' => $child_doc_master['doc_revisions'][0]['id'],
                            'doc_status_master_id' => $statusId,
                            'start_date' => new FrozenTime(date($datetimeformat)),
                            'end_date' => new FrozenTime(date($datetimeformat)),
                            'step_completed' => $doc_step_completed,
                            'comments' => "Document Training Assign",
                            'next_action_by' => '', 
                            'action_by' => $this->Authentication->getIdentity('User')->id,
                            'action_taken' => 'Submit',
                            'doc_data' => ''
                        ]);
                        $childStatusLog = $this->DocStatusLog->save($childStatusLog);
                    }
                  }
    
                $this->Flash->success(__('The doc master has been saved.'));
                return $this->redirect(["plugin" => false, 'controller' => 'doc-master', 'action' => 'index']);
            } 
            $this->Flash->error(__('The doc master could not be saved. Please, try again.'));
        }

        if($isDocSeperate != "Y")
        {
            
            $trainingCompliance = $this->TrainingCompliance->find('all', ["contain" => ["TrainingMaster", "TrainingBatch"], "conditions" => ['TrainingMaster.training_for_model_name' => 'DocMaster', 'TrainingMaster.training_for_model_id' => $docId]])->toArray();
           
            $trainingMster_id = !empty($trainingCompliance)?$trainingCompliance[0]->training_master->id:'';
            $trainingMaster = $this->TrainingMaster->find('all', ["contain" => ['TrainingTypeSubtypeMaster',"TrainingBatch"=>['TrainingStatusMaster','TrainingCompliance'=>['Assigned'],"Instructor"]], "conditions" => ['TrainingMaster.id'=>$trainingMster_id]])->toArray();
            $trainingBatch = $this->TrainingBatch->find('all', ["contain" => ["TrainingMaster"], "conditions" => ['TrainingBatch.training_for_model_name' => 'DocMaster', 'TrainingBatch.training_for_model_id' => $docId]])->toArray();
            $trainingCompliance = $this->TrainingCompliance->find('all', ["contain" => ["TrainingMaster", "TrainingBatch", "Assigned"], "conditions" => ['training_id' => $trainingMster_id]])->toArray();
            
            $this->loadModel('Tc.TrainingBatch');
            $this->loadModel('Tc.TrainingMaster');
            $this->loadModel('Tc.TrainingCompliance');
            $this->loadModel('Tc.TrainingSessionSlots');
            $Batch = $this->TrainingMaster->find('all', [
                "contain" => [
                    'TrainingCompliance' => ['Assigned','TrainingSlotAttendance'=>['TrainingBatch',
                        'Users' => [
                            'fields' => ["userfullname", "emp_id"],
                        ]],
                        'TrainingBatch',
                    ],
                ],
                'order' => ['TrainingMaster.id DESC'],
                'conditions' => ['TrainingMaster.id' => $trainingMster_id],
            ])->toArray();
            

        $lastStatusLog = $this->DocStatusLog->find('all', ['conditions' => ['doc_master_id' => $docId]])->last();
        $currentLog = [];
        $preLog = [];
        if (!empty($docRevision)) {
            if ($statusId != '' && $docRevision['id'] != '' && $docId != '') {
                $currentLog = $this->DocStatusLog->find('all', ['conditions' => ['doc_master_id' => $docId, 'doc_revision_id' => $docRevision['id'], 'doc_status_master_id' => $statusId, "DocStatusLog.action_taken != 'Reject'"]])->last();
            }
            if ($prevStatusId != '' && $docRevision['id'] != '' && $docId != '') {
                $preLog = $this->DocStatusLog->find('all', ['conditions' => ['doc_master_id' => $docId, 'doc_revision_id' => $docRevision['id'], 'doc_status_master_id' => $prevStatusId, "DocStatusLog.action_taken != 'Reject'"]])->last();
            }
        }
        $docStatusMasters = $this->DocMaster->DocStatusMaster->find('all')->toArray();
       
        $this->loadModel('Departments');
        foreach ($trainingMaster as $k=>$master)
        {
            $depts = $master['selected_department'];
            if (isset($depts) && $depts!=null ) {
                $depts = json_decode($depts,true);
                $deptlist = $this->Departments->find('list',['keyfield'=>'id','valueField'=>'department'])->where(['id IN'=>$depts])->toArray();
                $deptlist = implode(' , ', $deptlist);
                $master->deptlist = $deptlist;
                //debug($trainingMaster->$k);
            }
        }
        $this->loadModel('TrainingTypeSubtypeMaster');
        $subtypes = $this->TrainingTypeSubtypeMaster->find('list', ['keyField' => 'id','valueField' => 'name'])->where(["type"=>'Training','parent_id'=>1])->toArray();
        
        $condition =['TrainingCompliance.customer_id IN' => [0,$session['customer_id']],
            'TrainingCompliance.is_for_current_role_or_dept IN'=>[1],
            'Users.base_location_id' => $session['base_location_id'],'Users.active' => 1,'Users.del_status' => 1];
        $QueryAllData =$this->TrainingCompliance->find('all',['disabledBeforeFind'=>true])->where([$condition]); 
        
        $TrainingComp = $QueryAllData->select([
            'training_id',
            'assigned' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                $QueryAllData->newExpr()->add(['status' => 0]), // Condition for 'all'
                $QueryAllData->newExpr()->add(['status' => 1]),
                $QueryAllData->newExpr()->add(['status' => 2]),
                $QueryAllData->newExpr()->add(['status' => 3])
            ],
                ['literal' => 1],
                ['integer']
                )),
         
            'complete' => $QueryAllData->func()->sum($QueryAllData->newExpr()->addCase([
                $QueryAllData->newExpr()->add(['status' => 3]), // Condition for 'complete'
                $QueryAllData->newExpr()->add(['status' => 4])
            ],
                ['literal' => 1],
                ['integer']
                ))
        ])
        ->contain(['Users','TrainingMaster' => array(
            'fields' => ['TrainingMaster.training_name','TrainingMaster.type', 'TrainingMaster.evaluation_type'])])
            ->group(['training_id'])
            ->order(['training_id'])->toArray();
        
        $trainMastersss =$trainingMaster;
        $newtrainMasters = $this->AddComplianceToTrainingMaster($trainMastersss,$TrainingComp);
        //debug($newtrainMasters);die;
        $this->set(compact('newtrainMasters','Batch','subtypes','trainingMaster','transPass','trainingBatch', 'trainingCompliance', 'docMaster', 'lastStatusLog', 'currentLog', 'preLog','statusId','docStatusMasters'));
        }
        else { 
           
            $lastStatusLog = $this->DocStatusLog->find('all', ['conditions' => ['doc_master_id' => $docId]])->last();
            $currentLog = [];
            $preLog = [];
            if (!empty($docRevision)) {
                if ($statusId != '' && $docRevision['id'] != '' && $docId != '') {
                    $currentLog = $this->DocStatusLog->find('all', ['conditions' => ['doc_master_id' => $docId, 'doc_revision_id' => $docRevision['id'], 'doc_status_master_id' => $statusId, "DocStatusLog.action_taken != 'Reject'"]])->last();
                }
                if ($prevStatusId != '' && $docRevision['id'] != '' && $docId != '') {
                    $preLog = $this->DocStatusLog->find('all', ['conditions' => ['doc_master_id' => $docId, 'doc_revision_id' => $docRevision['id'], 'doc_status_master_id' => $prevStatusId, "DocStatusLog.action_taken != 'Reject'"]])->last();
                }
            }
            $docStatusMasters = $this->DocMaster->DocStatusMaster->find('all')->toArray();
            $this->set(compact('transPass','docMaster', 'lastStatusLog', 'currentLog', 'preLog','statusId','docStatusMasters','trainingCompliance'));
        }
        
      }
    
      public function startOnlineExam($user_id=null,$training_compliance_id=null,$training_master_id=null)
      {
          $user_id = ($user_id == null) ? null : decryptVal($user_id);
          $training_compliance_id = ($training_compliance_id == null) ? null : decryptVal($training_compliance_id); 
          $training_master_id = ($training_master_id == null) ? null : decryptVal($training_master_id);
          $startOnline = false;
          $session = $this->request->getSession()->read('Auth');
          $plugin=$this->request->getParam('plugin');
          $method=$this->request->getParam('action');
          $controller=$this->request->getParam('controller');
          $Training_attempts = CustomerCache::read("training_exam_attempt");
          
          $maxAtktAllowedValue = CustomerCache::read("max_atkt_allowed");
          $maxAtktAllowed =    isset($maxAtktAllowedValue)?$maxAtktAllowedValue:0;
          $isTrainingRemove = false;
          $loggedUserId=$this->request->getSession()->read('Auth')['id'];
          $session=$this->request->getSession()->read("Auth");
          $customer_id=$session['customer_id'];
          $location_id=$session['base_location_id'];
          $this->loadModel('Tc.TrainingCompliance');
          $this->loadModel('Tc.TrainingMaster');
          $this->loadModel('Tc.SectionMaster');
          $this->loadModel('Tc.QuestionBankMaster');
          $this->loadModel('Tc.QuestionBankMcqOption');
          $this->loadModel('Tc.TrainingSections');
          
          $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
          $session = $this->request->getSession()->read('Auth');
          if (!$this->Authorization->can($trainingCompliance, 'index')) {
              $this->Flash->error(__('You are not authorized to access this page!!!'));
              return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
          }
          
          
          
          if ($this->request->is(['patch', 'post', 'put'])) {
              $saveData = $this->request->getData();//debug($saveData);die;
              $this->loadModel('TrainingTestResult');
              $trainingCompliance = $this->TrainingCompliance->get($saveData['training_compliance_id'],[
                  'contain' => ['TrainingMaster','Users']]);
                 $passingScore = $trainingCompliance->training_master->passing_score;
              $attempt=$trainingCompliance->attempt+1;
              $trgType = $trainingCompliance->training_master->type;
              
              $answerskeys= array_keys($saveData['TrainingTestResult']);
              $answersvalues= array_values($saveData['TrainingTestResult']);
              $status = 1;
              $is_active = "Y";
              $testResultData = array();
              foreach ($answersvalues as $key=>$answer)
              {
                  $testResultData[$key]['training_master_id'] = $saveData['training_master_id'];
                  $testResultData[$key]['training_complaince_id'] = $saveData['training_compliance_id'];
                  $testResultData[$key]['user_id'] = $saveData['user_id'];
                  $testResultData[$key]['question_bank_master_id'] = $answerskeys[$key];
  
                  if($saveData['section_type'] == '2'){
                      $testResultData[$key]['question_bank_mcq_options_id'] = !empty($answer)?$answer:0;
                      $testResultData[$key]['descriptive_answer'] = null;
  
                  }else{
                      $testResultData[$key]['question_bank_mcq_options_id'] = null;
                      $testResultData[$key]['descriptive_answer'] = !empty($answer)?$answer:0;
                  }
                  $testResultData[$key]['attempt'] = !empty($attempt)?$attempt:0;
                  $testResultData[$key]['exam_date'] = new FrozenTime();
                  
              }
              
              
              $qusetionMaster = $this->QuestionBankMaster->find('list',['keyField'=>'id','valueField' =>'question_bank_mcq_options_id'])->where(['id IN'=>$answerskeys])->toArray();
              $totalMarksObtain=0;
              $totalQuestions = count($answerskeys);
              foreach ($saveData['TrainingTestResult'] as $examKey=>$examRes )
              {
                  $correctAns = $qusetionMaster[$examKey];
                  $givenAns = $examRes;
                  if($correctAns == $givenAns )
                  {
                      $totalMarksObtain=$totalMarksObtain+1;
                  }
              }
             $marksAverage = round($totalMarksObtain / $totalQuestions * 100);                       
              $testResult = $this->TrainingTestResult->newEntities($saveData);
              $trainingRes = $this->TrainingTestResult->patchEntities($testResult, $testResultData);
              $testresultSave = $this->TrainingTestResult->saveMany($trainingRes);
              
             
              if($marksAverage >= $passingScore)
              {
                  $status = 3;
                  $is_active = "N";
                  
                  
              }
              elseif ((int)$Training_attempts == $attempt)
              {
                  $status = 4;
                  $is_active = "Y";
                  
              }
              else{
                  $status = 1;
                  $is_active = "Y";
              }
              $trainCompUpdateData=[];
              $trainCompUpdateData['score'] = $marksAverage ;
              $trainCompUpdateData['attempt'] = $attempt ; // Attempt don't to be update in existing complience
              $trainCompUpdateData['is_present'] = 1 ;
              $trainCompUpdateData['status'] = $status ; 
              $trainCompUpdateData['is_active'] = $is_active ;
              //$trainCompUpdateData['assign_refresher_flag'] = 0 ;
             
              $trainCompUpdateData['completed_date'] = new FrozenTime(date($this->DateTimeFormat)); ; 
              $trainingCompl = $this->TrainingCompliance->patchEntity($trainingCompliance, $trainCompUpdateData);
              $trainingCompSave = $this->TrainingCompliance->save($trainingCompl);
             
              if(isset($status) && $status == 4)
              {
                 $lastRec =  $this->TrainingCompliance->find('all',['fields'=>['due_date','department_id','training_batch_id','trg_batch_id_for_report','attempt','atkt_count',"is_assign_elarning_flag"]])->where(['id'=>$training_compliance_id])->last();
                 $trg_batch_id_for_reports = $lastRec->training_batch_id;
                 if($trg_batch_id_for_reports == 0){
                     $trg_batch_id_for_reports = $lastRec->trg_batch_id_for_report;
                 }
                 //if its inperson then status should be 0 i.e. unscheduled 
                 //for elearning it should be 1 as no scheduling is rquired for elearning
                 $newStatus = 0;
                 if ($trgType == 2) {
                  $newStatus = 1;
                 }
                  $trainComplEmptyEntity =  $this->TrainingCompliance->newEmptyEntity();
                  $trainCompNewData=[];
                  $trainCompNewData['customer_id'] = $customer_id ;
                  $trainCompNewData['training_id'] = $training_master_id ;
                  $trainCompNewData['score'] = null ;
                  $trainCompNewData['attempt'] = 0;//$lastRec->attempt; set attempt 0 For Fail Entry
                  $trainCompNewData['is_present'] = 0 ;
                  $trainCompNewData['status'] = $newStatus;
                  $trainCompNewData['is_active'] = 'Y' ;   
                  $trainCompNewData['user_id'] = $user_id ;
                  $trainCompNewData['due_date'] = $lastRec->due_date ;
                  $trainCompNewData['department_id'] = $lastRec->department_id ;
                  $trainCompNewData['trg_batch_id_for_report'] = $trg_batch_id_for_reports;
               
                  $trainType = ($lastRec->is_assign_elarning_flag == 1)?1:0;
                  $trainCompNewData['is_assign_elarning_flag'] = $trainType;

                  $trainCompNewData['atkt_count'] = $lastRec->atkt_count + 1; 
  
                  $trainingComplNew = $this->TrainingCompliance->patchEntity($trainComplEmptyEntity, $trainCompNewData);
                  $trainingCompSave = $this->TrainingCompliance->save($trainingComplNew);
  
                  //added By Atish
                    
                    if($trainingCompSave['atkt_count'] >= $maxAtktAllowed && $maxAtktAllowed != 0){
                      $tcIds  = $this->TrainingCompliance->find('list', ['keyField' => 'id','valueField' => 'id'])->where(['TrainingCompliance.training_id'=>$trainingCompSave['training_id'],"TrainingCompliance.user_id"=>$trainingCompSave['user_id']])->toArray();
                      $this->TrainingCompliance->updateAll(['is_for_current_role_or_dept'=>0],['TrainingCompliance.id IN'=>$tcIds]);
                      $isTrainingRemove = true;
                    }
                  //ended
              }
              
              if($trainingCompSave)
              {
                  if (!empty($user_id)) {
                    // //   $notification = new SimpleNotification([
                    // //       "notification_inbox_data" => [
                    // //           "customer_id" => $customer_id,
                    // //           "created_by" => $session['id'],
                    // //           "user_type" => "Users",   // accepts User|Groups|Departments
                    // //           "user_reference_id" => $user_id, // relavtive id
                    // //           "title" => '', // title of notification
                    // //           //"comments" => "You have successfuly Completed Training Evaluation", // content of notification
                    // //           "plugin_name" => 'Tc', // for which plugin_name you are highlighting. if required
                    // //           "model_reference_name" => "TrainingCompliance", // for which plugin reference name   if required
                    // //           "model_reference_id" => $training_compliance_id, //   if required
                    // //           "action_link" => ["plugin" => 'Tc', "controller" => "TrainingCompliance", "action" => "usertrainingindex"] // link to redirect to user.
                    // //       ],
                    //   ]);
                    //   $notification->send();
                  }
                   // Online exam notification for trainee
                $title='New Training added';
                $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
                $notification_identifier = 'online_exam_'.$status;
                // debug($notification_identifier);die;
                
                // debug($trainingCompliance);die;
                $notificationData = [
                    'selected_users' => $saveData['user_id'],
                    'training_name' =>$trainingCompliance->training_master->training_name,
                    'customer_id'=>$customer_id,
                    'attempt'=>$trainingCompliance->attempt,
                    'notification_identifier'=>$notification_identifier,
                    "action_link"=>["plugin"=>'Tc', "controller"=>"TrainingCompliance","action"=>"mycompletedTrainings"] // link to redirect to user.

                ];
                $this->loadComponent('CommonMailSending');
                $this->CommonMailSending->selected_email_details($customer_id,$location_id,$session->id,$notificationData,$loggedUserEmailId);
                $this->loadComponent('QmsNotification');
                $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$session->base_location_id,$session->id,$notificationData,$session->$location_id);
                         
                  $notificationData = [
                      'session_data' =>$trainingCompliance,
                      'customer_id'=>$customer_id,
                      'created_by'=>$loggedUserId,
                      'notification_identifier'=>'close_session',
                      //"action_link"=>["plugin"=>'Tc', "controller"=>"TrainingBatch","action"=>"attendance",$id,encryptVal($prevStatusId),encryptVal($currentStatusId),encryptVal($previousStepStatusid)] // link to redirect to user.
                  ];
                  $this->loadComponent('QmsNotification');
                  $this->QmsNotification->notificaion_details($plugin,$controller,$method,$customer_id,$location_id,$loggedUserId,$notificationData);
                  $user_ids = intval($user_id);
                  $training_compliance_ids = intval($training_compliance_id);
                  $training_master_ids = intval($training_master_id);
                  if($trgType == 2 && $status == 4 && $attempt == 3){
                      $notificationData = [
                          'selected_users' => $trainingCompliance->training_master->created_by,
                          'training_name' =>$trainingCompliance->training_master->training_name,
                          'trainee_name'=>$trainingCompliance->user->userfullname,
                          'customer_id'=>$customer_id,
                          'notification_identifier'=>'falied_user',
                          "action_link"=>["plugin"=>'Tc', "controller"=>"TrainingCompliance","action" => 'myLastEvaluation',($user_ids),($training_compliance_ids),($training_master_ids),($attempt)] // link to redirect to user.
                          
                      ];
                      $this->loadComponent('QmsNotification');
                      $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$session->base_location_id,$session->id,$notificationData);
                      
                  }
                  
                  
                  
                  if($isTrainingRemove == true){ 
                    $this->Flash->error(__("All Attempts for ". $trainingCompliance->training_master->training_name ." are completed...!!! & user is Disqualified for this Training.."));
                    return $this->redirect(['action' => 'usertrainingindex']);
                  }else{
                      $startOnline = true;
                    $this->Flash->success(__('The evaluation has been saved.'));
                    return $this->redirect(['action' => 'myLastEvaluation',encryptVal($user_id),encryptVal($training_compliance_id),encryptVal($training_master_id),encryptVal($attempt),$startOnline]);
                  }
                }
              else{
                  $this->Flash->error(__('The evaluation could not be saved. Please, try again.'));
              }
          }
           $sections = $this->TrainingSections->find('all')->where(['training_master_id'=>$training_master_id])->toArray();
           $trainingMaster = $this->TrainingMaster->find('all',['fields'=>['number_of_question','type','id']])->where(['id'=>$training_master_id])->toArray();
           $questionMaster = []; 
           
           if (isset($sections) && !empty($sections[0]->section_master_id)) {
              $sectionids=array();
              
              $totalQuestion = !empty($trainingMaster[0]->number_of_question)?$trainingMaster[0]->number_of_question:10;
               foreach ($sections as $k=>$section)
              {               
                 $mul = $section->weightage * $totalQuestion;
                 $ques_limit = round($mul / 100) ;
                 $sectionids[$section->section_master_id]=$ques_limit;
                 $questiondata=$this->QuestionBankMaster->find('all', [
                                 'contain'=>['QuestionBankMcqOptions','SectionMaster'],
                                 'conditions'=>['QuestionBankMaster.customer_id'=>$session['customer_id'],
                                         'QuestionBankMaster.customer_location_id'=>$location_id,
                                          'QuestionBankMaster.status'=>1, 
                                         //'EmployeeRoles.role_id IN'=> $role_id
                                     ]
                 ])->where(['QuestionBankMaster.section_master_id IN'=>$section->section_master_id])->order('rand()')->limit($ques_limit)->toArray();
                 //$questionMaster[$k] = $questiondata;
                 foreach ($questiondata as $qdata)
                 {
                     array_push($questionMaster,$qdata);
                 }
                 
               
              }
              
              
              if (empty($questionMaster)) {
                  $this->Flash->error(__('No Questions Found For the Exam . Please, try again.'));
                  $this->redirect($this->referer());
              }
              
          }
          else {
              $this->Flash->error(__('No Sections Added For this Training . Please, Assign Sections.'));
              $this->redirect($this->referer());
          }
          
  //          if (isset($sectionData)) {
  //             $questionMaster=$this->QuestionBankMaster->find('all', [
  //                 'contain'=>['QuestionBankMcqOptions',],
  //                 'conditions'=>['QuestionBankMaster.customer_id'=>$session['customer_id'],
  //                     'QuestionBankMaster.customer_location_id'=>$location_id,
  //                     //'EmployeeRoles.role_id IN'=> $role_id
  //                 ]
  //             ])->where(['QuestionBankMaster.section_master_id IN'=>$sectionData])->order('rand()')->toArray();
  //         }
          $isTransPassword = CustomerCache::read("transactional_password_required");
          if ($isTransPassword == 'Y') {
              $transPass  = 1;
          }
          else {
              $transPass = 0;
          }
          $this->set(compact('questionMaster','training_compliance_id','training_master_id','user_id','transPass','trainingMaster'));
      }
    
      public function myLastEvaluation($user_id=null,$training_compliance_id=null,$training_master_id=null,$attempt=null,$startOnline=null)
    {
        $user_id = ($user_id == null) ? null : decryptVal($user_id);
        $training_compliance_id = ($training_compliance_id == null) ? null : decryptVal($training_compliance_id);
        $training_master_id = ($training_master_id == null) ? null : decryptVal($training_master_id);
        $attempt = ($attempt == null) ? null : decryptVal($attempt);
        $startOnline = ($startOnline == null) ? null : $startOnline;
        
        
        $session=$this->request->getSession()->read("Auth");
        $customer_id=$session['customer_id'];
        $location_id=$session['base_location_id'];
        $this->loadModel('Tc.TrainingCompliance');
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('Tc.SectionMaster');
        $this->loadModel('Tc.QuestionBankMaster');
        $this->loadModel('Tc.QuestionBankMcqOptions');
        $this->loadModel('Tc.TrainingTestResult');
        
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        if (!$this->Authorization->can($trainingCompliance, 'MyLastEvaluation')) {
            $this->Flash->error(__('You are not authorized to access this page!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
      
        $trainingCompliance = $this->TrainingCompliance->get($training_compliance_id,['contain'=>['TrainingMaster',],'disabledBeforeFind'=>true]);
      
        $questionMaster=$this->TrainingTestResult->find('all', [
            'contain'=>['TrainingMaster','QuestionBankMaster','TrainingCompliance'],//             
        ])->where(['TrainingTestResult.training_master_id'=>$training_master_id,'TrainingTestResult.training_complaince_id'=>$training_compliance_id,'TrainingTestResult.attempt'=>$attempt])->toArray()
        
        ;
        
        $questionIds=[];
        $questionMasterMcqData='';
      foreach ($questionMaster as $key=>$question)
     {
         $questionIds[$key]=$question->question_bank_master_id;
     }
     if(!empty($questionIds)){
         $questionMasterMcqData=$this->QuestionBankMaster->find('all',['contain'=>['QuestionBankMcqOptions']])->where(['id IN'=>$questionIds])->toArray();
     }
     
     $this->set(compact('questionMaster','training_compliance_id','training_master_id','user_id','attempt','questionMasterMcqData','trainingCompliance','startOnline'));
        
        
    }
    
    function docEmailAndNotification($id=null,$notificationIdentifier=null,$status_id=1,$start_date=null,$trainint_total_time=null,$trainint_evidence_upload_by=null,$sendmethod=null){
        
        $plugin=$this->request->getParam('plugin');
        $controller=$this->request->getParam('controller');
        $method=$this->request->getParam('action');
        $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        $loggedUserDeptId=$this->request->getSession()->read('Auth')['departments_id'];
        $customer_locations_id= $this->request->getSession()->read('Auth')['base_location_id'];
        $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
        // debug($notificationIdentifier);die;
        $this->loadComponent('WiseWorks');
        $this->loadModel('DocStatusMaster');
        $this->loadModel('DocMaster');
         
        if($method == 'trainingEvidence'){
            $controller = 'DocMaster';
            $plugin = 'doc';
        }
        if ($sendmethod!=null) {
            $method =$sendmethod;
        }
        $docMaster = $this->DocMaster->get($id,[
            'contain' => ['Customer', 'DocFormatsMaster', 'CustomerLocations' => ['Countries', 'State', 'City'],
                'Departments', 'DocStatusLog', 'CreatedByUser','ProductsMaster','DocStatusLog'=>['NextActionByUser','ActionByUser'],
                'DocRevisions' => ['DocStatusLog' => ['NextActionByUser'], 'sort' => ['id' => 'desc']]         
                ]
        ]);
        $docStatusLogLastRecord = end($docMaster['doc_status_log']);
      
        $currentStatusId = $this->WiseWorks->getNextStep($status_id, $docMaster['id'],$allstep=null,'Doc');
        $nextStatusId = $this->WiseWorks->getNextStep($currentStatusId, $docMaster['id'],$allstep=null,'Doc');
        $prevStatusId = $status_id;
        $nextStep = $this->DocStatusMaster->find('all', ['fields' => ['form_name'], 'conditions' => ['id' => $nextStatusId]])->last();
        if($prevStatusId==null){
            $prevStatusId=1;
        }
        
       $notificationData = [
           'id'=>$docMaster['id'],
           'title'=>$docMaster['title'],
           'doc_no'=>$docMaster['doc_no'],
           'departments_id'=>$docMaster['department']['department'],
           'prepared_by'=>$docMaster['created_by_user']['first_name'],
           'format_type'=>$docMaster['doc_formats_master']['format_name'],
           'current_status_id'=>'',
           'nextStatusId'=>$nextStatusId,
           'prevStatusId'=>$prevStatusId,
           'notification_identifier'=>$notificationIdentifier,
           'created'=>$docMaster['created'],
           'created_by'=>$docMaster['created_by'],
           'start_date'=>$start_date!=null?$start_date:'',
           'trainint_total_time'=>$trainint_total_time!=null?$trainint_total_time:'',
           'trainint_evidence_upload_by'=>$trainint_evidence_upload_by!=null?$trainint_evidence_upload_by:'',

        ]; 
        // debug($notificationData);die;
       $this->loadComponent('CommonMailSending');
       $this->CommonMailSending->email_details($plugin,$controller,$method,$customer_id,$customer_locations_id,$loggedUserId,$notificationData,$loggedUserEmailId);
       $this->loadComponent('QmsNotification');
       $this->QmsNotification->notificaion_details($plugin,$controller,$method,$customer_id,$customer_locations_id,$loggedUserId,$notificationData,$loggedUserEmailId);
       
    }
    function selectdDocEmailAndNotification($id=null,$notificationIdentifier=null,$status_id=1,$user_id = null,$start_date=null,$trainint_total_time=null,$trainint_evidence_upload_by=null){
        
        $plugin=$this->request->getParam('plugin');
        $controller=$this->request->getParam('controller');
        $method=$this->request->getParam('action');
        $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        $loggedUserDeptId=$this->request->getSession()->read('Auth')['departments_id'];
        $customer_locations_id= $this->request->getSession()->read('Auth')['base_location_id'];
        $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
        // debug($notificationIdentifier);die;
        $this->loadComponent('WiseWorks');
        $this->loadModel('DocStatusMaster');
        $this->loadModel('DocMaster');
        $docMaster = $this->DocMaster->get($id,[
            'contain' => ['Customer', 'DocFormatsMaster', 'CustomerLocations' => ['Countries', 'State', 'City'],
                'Departments', 'DocStatusLog', 'CreatedByUser','ProductsMaster','DocStatusLog'=>['NextActionByUser','ActionByUser'],
                'DocRevisions' => ['DocStatusLog' => ['NextActionByUser'], 'sort' => ['id' => 'desc']]         
                ]
        ]);
        $docStatusLogLastRecord = end($docMaster['doc_status_log']);
     
       
        $currentStatusId = $this->WiseWorks->getNextStep($status_id, $docMaster['id'],$allstep=null,'Doc');
        $nextStatusId = $this->WiseWorks->getNextStep($currentStatusId, $docMaster['id'],$allstep=null,'Doc');
        $prevStatusId = $status_id;
        $nextStep = $this->DocStatusMaster->find('all', ['fields' => ['form_name'], 'conditions' => ['id' => $nextStatusId]])->last();
        if($prevStatusId==null){
            $prevStatusId=1;
        }
        
       $notificationData = [
           'selected_users'=>$user_id,
           'id'=>$docMaster['id'],
           'title'=>$docMaster['title'],
           'doc_no'=>$docMaster['doc_no'],
           'departments_id'=>$docMaster['department']['department'],
           'prepared_by'=>$docMaster['created_by_user']['first_name'],
           'format_type'=>$docMaster['doc_formats_master']['format_name'],
           'current_status_id'=>'',
           'nextStatusId'=>$nextStatusId,
           'prevStatusId'=>$prevStatusId,
           'notification_identifier'=>$notificationIdentifier,
           'created'=>$docMaster['created'],
           'created_by'=>$docMaster['created_by'],
           'start_date'=>$start_date!=null?$start_date:'',
           'trainint_total_time'=>$trainint_total_time!=null?$trainint_total_time:'',
           'trainint_evidence_upload_by'=>$trainint_evidence_upload_by!=null?$trainint_evidence_upload_by:'',
           "action_link"=>["plugin"=>'Tc', "controller"=>"TrainingBatch","action"=>"indexWisework"]
        ]; 
       $this->loadComponent('CommonMailSending');
       $this->CommonMailSending->selected_email_details($customer_id,$customer_locations_id,$loggedUserId,$notificationData,$loggedUserEmailId);
       $this->loadComponent('QmsNotification');
       $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$customer_locations_id,$loggedUserId,$notificationData,$loggedUserEmailId);
    //    die;
    }
    
    public function assignUsersToTraining($training_master_id=null)
    {
        $training_master_id = ($training_master_id == null) ? null : decryptVal($training_master_id);
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $session=$this->request->getSession()->read("Auth");
        $this->loadModel('Tc.TrainingMaster');
        if (!$this->Authorization->can($trainingCompliance, 'index')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $trainingMaster = $this->TrainingCompliance->TrainingMaster->get($training_master_id,[
            'contain' => ['Customer','TrainingRoleMap','TrainingCompliance'=>['Users'=>['Departments']],'TrainingBatch','TrainingMasterDate'=>['sort'=>['id'=>'desc']]],
        ]);
        $selectedUsers = array();
        $ExistingUsers = array();
        if(isset($trainingMaster->training_compliance))
        {
            foreach ($trainingMaster->training_compliance as $key=>$TraingComp )
            {
                $ExistingUsers[$key] = $TraingComp->user_id;
                $User = $TraingComp->user;
                $userNameString = $User->username.' | '.$User->userfullname.' | '.$User->department->department;
                $selectedUsers[$TraingComp->user_id]=$userNameString;
            }
        }
        $customer_id=$trainingMaster['customer_id'];
        if ($this->request->is(['patch', 'post', 'put'])) {
            $saveData=$this->request->getData();//debug($saveData);die;
            $role_id=$saveData['role_id'];
            $status = 1;
            $department_id=$saveData['department_id'];
            $sectionID =$saveData['section'];
            //$due_date=$saveData['training_master_date'];
            $saveData['selected_department']=json_encode($department_id);
            $saveData['selected_roles']=json_encode($role_id);
            $saveData['section_master_id']=json_encode($sectionID);
            $this->loadModel('SectionMaster');
            $this->loadModel('Departments');
            $this->loadModel('FunctionalRoles');
            $departments=$this->Departments->find('list',['keyfield'=>'id','valueField'=>'id'])->where(['Departments.customer_id'=>$customer_id])->toArray();
            $functionalRoles=$this->FunctionalRoles->find('list',['keyfield'=>'id','valueField'=>'id'])->where(['FunctionalRoles.customer_id'=>$customer_id])->toArray();
            $section = $this->SectionMaster->find('list', ['keyField' => 'id','valueField' => 'name'])->where(['SectionMaster.customer_id'=>$customer_id,'SectionMaster.customer_location_id'=>$session['base_location_id']])->toArray();
            if(($trainingMaster['selected_roles'] != $saveData['selected_roles']) || ($trainingMaster['selected_department'] != $saveData['selected_department'])){
                $trainingRoleMapids=$this->TrainingMaster->TrainingRoleMap->find('all',['conditions'=>['TrainingRoleMap.training_id'=>$id]])->toArray();
                if(!empty($trainingRoleMapids) && count($trainingRoleMapids) > 0){
                    $this->loadComponent('Common');
                    $this->Common->auditTrailForDeleteAll(array_column($trainingRoleMapids,'id'),'training_role_map','training_master',$id);
                }
                if($department_id != '' && $role_id != ''){
                    foreach($department_id as $d=>$dept){
                        foreach($role_id as $k=>$role){
                            $saveData['training_role_map'][]=['department_id'=>$dept,'role_id'=>$role];
                        }
                    }
                }else if($department_id == '' && $role_id != ''){
                    if(!empty($departments)){
                        foreach($departments as $d=>$dept){
                            foreach($role_id as $k=>$role){
                                $saveData['training_role_map'][]=['department_id'=>$dept,'role_id'=>$role];
                            }
                        }
                    }else{
                        $this->Flash->error(__('The training master could not be save. To add Training systems at least have one department and functional role.'));
                        return $this->redirect(['action' => 'index']);
                    }
                }else if($department_id != '' && $role_id == ''){
                    if(!empty($functionalRoles)){
                        foreach($department_id as $d=>$dept){
                            foreach($functionalRoles as $k=>$role){
                                $saveData['training_role_map'][]=['department_id'=>$dept,'role_id'=>$role];
                            }
                        }
                    }else{
                        $this->Flash->error(__('The training master could not be save. To add Training systems at least have one department and functional role.'));
                        return $this->redirect(['action' => 'index']);
                    }
                }else{
                    
                }
                $this->TrainingMaster->TrainingRoleMap->deleteAll(['TrainingRoleMap.training_id'=>$id]);
                
            }
            unset($saveData['role_id']);
            unset($saveData['department_id']);
            //debug($saveData);die;
            $trainingMaster = $this->TrainingMaster->patchEntity($trainingMaster, $saveData);
            if(!empty($due_date)){
                $trainingMaster['due_date']=$due_date[0]['due_date'];
            }
            //debug($trainingMaster);die;
            $result=$this->TrainingMaster->save($trainingMaster);
            
            //debug($result);die;
            if ($result) {
                //$role_map=$result->training_role_map;
                $this->loadComponent('Common');
                $this->Common->updateTrainingRoleMap($customer_id,'applicable_for');
                
                $this->loadModel('TrainingCompliance');
                $this->loadModel('Users');
                $training_master_id=$result->get('id');
                
                $trainingComplianceData = [];
                $deleteEmpData = [];
                
                foreach ($ExistingUsers as $key => $val) {
                    if(!in_array($val, $saveData['employees']))
                    {
                        array_push($deleteEmpData,$val);
                        
                    }
                }
                
                if(isset($deleteEmpData) && !empty($deleteEmpData))
                {
                    $condition= array('training_id' => $id,'TrainingCompliance.user_id IN' => $deleteEmpData);
                    $allDeleted= $this->TrainingCompliance->deleteAll($condition);
                }
                
                foreach ($saveData['employees'] as $key => $value) {
                    if(!empty($ExistingUsers))
                    {
                        if(!in_array($value, $ExistingUsers))
                        {
                            
                            $userData = $this->Users->find('all',['fields'=>['departments_id','functional_role_id']])->where(['id'=>$value])->first();
                            $trainingComplianceData[$key]['customer_id'] = $customer_id;
                            $trainingComplianceData[$key]['training_id'] = $training_master_id;
                            $trainingComplianceData[$key]['user_id'] = $value;
                            $trainingComplianceData[$key]['created_by'] = $this->Authentication->getIdentity('User')->id;
                            $trainingComplianceData[$key]['status'] = 0;
                            $trainingComplianceData[$key]['department_id'] = $userData->departments_id;
                            $trainingComplianceData[$key]['role_id'] =$userData->functional_role_id;
                        }
                        
                    }
                    else {
                        $userData = $this->Users->find('all',['fields'=>['departments_id','functional_role_id']])->where(['id'=>$value])->first();
                        $trainingComplianceData[$key]['training_id'] = $training_master_id;
                        $trainingComplianceData[$key]['customer_id'] = $customer_id;
                        $trainingComplianceData[$key]['user_id'] = $value;
                        $trainingComplianceData[$key]['created_by'] = $this->Authentication->getIdentity('User')->id;
                        $trainingComplianceData[$key]['status'] = 0;
                        $trainingComplianceData[$key]['department_id'] = $userData->departments_id;
                        $trainingComplianceData[$key]['role_id'] =$userData->functional_role_id;
                    }
                }
                
                if(isset($trainingComplianceData))
                {
                    $traningData = $this->TrainingCompliance->newEntities($this->request->getData());
                    $trainingCompliance = $this->TrainingCompliance->patchEntities($traningData, $trainingComplianceData);
                    if ($result = $this->TrainingCompliance->saveMany($trainingCompliance)) {
                        foreach ($result as $traningKey => $traningValue) {
                            $training_compliance_id = $traningValue['id'];
                            //if(isset($saveData['saveOnly'])){
                            if ($status != '') {
                                $this->loadModel('Tc.TrainingComplianceLog');
                                $status_log = $this->TrainingComplianceLog->newEntity([
                                    'training_compliance_id' => $training_compliance_id,
                                    'status_id' => $status,
                                    'start_date' => date('Y-m-d h:i:s'),
                                    'end_date' => date('Y-m-d h:i:s'),
                                    'action_taken' => 'Submit',
                                    'status_change_by' => $this->Authentication->getIdentity('User')->id,
                                    'comments' => 'New Training Master added'
                                ]);
                                $this->TrainingComplianceLog->save($status_log);
                            }
                        }
                        
                        $this->Flash->success(__('The training Compliance has been saved.'));
                        
                        $this->redirect($this->referer());
                    }
                }
                
            }
            else {
                $this->Flash->error(__('The training Compliance could not be saved. Please, try again.'));
                $this->redirect($this->referer());
            }
            
        }
        $selectedUsers = $this->getSelectedUsers($training_master_id);
        $customers = $this->TrainingMaster->Customer->find('list', ['keyField' => 'id','valueField'=>'company_name']);
        $this->loadModel('CustomerLocations');
        $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
        //Fetch customers location according to customer_id
        $customerLocations = $this->CustomerLocations->find('list', ['keyField' => 'id','valueField' => 'name'])->where(["CustomerLocations.customer_id"=>$customer_id]);
        
        $type=Configure::read('trainingtype');
        $frequency=Configure::read('trainingfrequency');
        $evaluationtype=Configure::read('evaluationtype');
        $this->loadModel('FunctionalRoles');
        $this->loadModel('Departments');
        $this->loadModel('SectionMaster');
        $roles = $this->FunctionalRoles->find('list', ['keyField' => 'id','valueField'=>'role_name'])->where(['FunctionalRoles.customer_id'=>$trainingMaster['customer_id']]);
        $departments = $this->Departments->find('list', ['keyField' => 'id','valueField'=>'department'])->where(['Departments.customer_id'=>$trainingMaster['customer_id']]);
        $sectiondata = $this->SectionMaster->find('list', ['keyField' => 'id','valueField' => 'name'])->where(['SectionMaster.customer_id'=>$customer_id,'SectionMaster.customer_location_id'=>$session['base_location_id']]);
        $this->set(compact('evaluationtype','departments','roles','trainingMaster','type', 'customers','frequency','customer_id','selectedUsers','customerLocations','sectiondata'));
        
    }
    
    public function addSessionsToTraining($training_master_id=null)
    {
        $training_master_id = ($training_master_id == null) ? null : decryptVal($training_master_id);
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $this->loadModel('TrainingBatch');
        $session = $this->request->getSession()->read('Auth');
        $customer_id= $session->customer_id;
        $location_id= $session->base_location_id;
        if (!$this->Authorization->can($trainingCompliance, 'index')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        $trainingMaster = $this->TrainingCompliance->TrainingMaster->get($training_master_id);
               
        $trainingComplience = $this->TrainingCompliance->find('All',['contain' =>['Users'=>['Departments']]])->where(['TrainingCompliance.training_id'=>$training_master_id,'TrainingCompliance.training_batch_id IS'=>null])->toArray();
        
        $selectedUsers = array();
        if(isset($trainingComplience))
        {
            foreach ($trainingComplience as $key=>$TraingComp )
            {
                $User = $TraingComp->user;
                $userNameString = $User->username.' | '.$User->userfullname.' | '.$User->department->department;
                $selectedUsers[$TraingComp->user_id]=$userNameString;
            }
        }
        
       
        
        if ($this->request->is(['patch', 'post', 'put'])) {
            $PostData = $this->request->getData();
            $employees = $this->request->getData('employees');
            $PostData['from_date']= date($this->DateTimeFormat,strtotime($PostData['from_date']));
            $PostData['to_date']= date($this->DateTimeFormat,strtotime($PostData['to_date']));
            $training_master_id =  $this->request->getData('training_master_id');
            if (isset($employees) && count($employees) > 0) {            
            $trainingBatch = $this->TrainingBatch->newEmptyEntity();
            //debug($PostData);
            $trainingBatch = $this->TrainingBatch->patchEntity($trainingBatch, $PostData);
            if ($this->TrainingBatch->save($trainingBatch)) {
                $training_batch_id=$trainingBatch->get('id');
                $update_Complience= $this->TrainingCompliance->updateAll(array('training_batch_id' => $training_batch_id), array('user_id IN' => $employees,'training_id'=>$training_master_id));
                if ($update_Complience) {
                    $this->Flash->success(__('The training Session has been saved.'));
                    $this->redirect(['plugin' => 'Tc', 'controller' => 'TrainingCompliance', 'action' => 'index']);
                }
                else
                {
                    $this->Flash->error(__('The training Session could not be saved. Please, try again.'));
                    $this->redirect(['plugin' => 'Tc', 'controller' => 'TrainingCompliance', 'action' => 'index']);
                }
                
                }
            }
            else {
                $this->Flash->error(__('Please Select Users For Session .'));
                $this->redirect($this->referer());
            }
            
        }
        
        $this->loadComponent('Common');
        $instructor = $this->Common->getUsers(['Users.active' => 1, 'Users.customer_id' => $customer_id,'Users.base_location_id' => $location_id]);
        
        $instructor_type = Configure::read('instructor_type');
        
        $this->set(compact('instructor_type','trainingMaster','selectedUsers','instructor'));
        
        
    }
    
    
    function getSelectedUsers ($trainingId)
    {
        $this->loadModel('Tc.TrainingMaster');
        $trainingMaster = $this->TrainingMaster->get($trainingId, [
            'contain' => ['Customer','TrainingRoleMap','TrainingCompliance'=>['Users'=>['Departments']],'TrainingBatch','TrainingMasterDate'=>['sort'=>['id'=>'desc']]],
        ]);
        
        $selectedUsers = array();
        if(isset($trainingMaster->training_compliance))
        {
            foreach ($trainingMaster->training_compliance as $key=>$TraingComp )
            {
                $User = $TraingComp->user;
                $userNameString = $User->username.' | '.$User->userfullname.' | '.$User->department->department;
                $selectedUsers[$TraingComp->user_id]=$userNameString;
            }
        }
        
        return $selectedUsers;
    }
    
    public function addViewLog()
    {
        $trainingIndex = $this->TrainingCompliance->newEmptyEntity();
        $this->loadModel('Tc.TrainingViewLog');
        $session = $this->request->getSession()->read('Auth');
        $logged_user_id = $session->id;
        $customer_id = $session->customer_id;
        $datetimeformat  = CustomerCache::read("datetimeformat");
        if($datetimeformat==null){
            $datetimeformat  = CustomerCache::read("datetimeformat",null,0);
        }
        if ($datetimeformat == null){
            $datetimeformat  = Configure::read("datetimeformat");
        }
        if (!$this->Authorization->can($trainingIndex, 'usertrainingindex')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        if ($this->request->is(['patch', 'post', 'put'])) {
            $PostData = $this->request->getData();
            // $SaveData = json_decode($PostData['viewlog']);
            $this->loadModel('Tc.TrainingCompliance');
            $trainingCompliance = $this->TrainingCompliance->get($PostData['trainingComplienceId'],["contain"=>["TrainingViewLog"=>["conditions"=>['end_datetime IS NULL']]]]);
            $traininigViewLog=[];                       
           
            
            if($PostData['mode'] == 'start'){
                $traininigViewLog=[
                    'training_compliance_id'=>$PostData['trainingComplienceId'],   
                    'training_master_id'=>$trainingCompliance->training_id,
                    'view_by'=>$logged_user_id,
                    'view_date'=>new FrozenTime(date($datetimeformat)),
                    'start_datetime'=>new FrozenTime(date($datetimeformat)),
                    'status'=>0 //  0 => WORK IN PROGRESS, 1=>Close
                ];
             }else{
                $traininigViewLog = end($trainingCompliance->training_view_log)->toArray();
                $traininigViewLog['end_datetime']=new FrozenTime(date($datetimeformat));
                $traininigViewLog['status']=1; 
                // $trainingViewLog = $this->TrainingViewLog->find('all')->where(['training_complaince_id'=>$SaveData->id,'status'=>0,'end_datetime IS NULL'])->last();
            }
            $newTimeSpent = 0;
            // debug($traininigViewLog);die;
            if($PostData['mode'] != 'start'){
            $datetime1 = $traininigViewLog['start_datetime'];
            $datetime2 = new FrozenTime(date($datetimeformat));
            $newTimeSpent = $datetime2->getTimestamp() - $datetime1->getTimestamp(); 

            }
            
            $totalTimeSpent = $trainingCompliance->total_reading_time + $newTimeSpent;
            $data['total_reading_time'] = $totalTimeSpent;
            $data['training_view_log'][0]=  $traininigViewLog;
            $trainingCompliance = $this->TrainingCompliance->patchEntity($trainingCompliance,$data,['associated'=>['TrainingViewLog']]);
            $result = $this->TrainingCompliance->save($trainingCompliance); 
            if ($result) {            
            $this->autoRender = false;
            $this->response = $this->response->withType('application/json')
            ->withStringBody(json_encode($result));
            return null;
            }
        }
        
    }
    public function report()
    {
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingReportIndex = $trainingCompliance;
        $this->loadModel('TrainingBatch');
        $this->loadModel('Departments');
        $this->loadModel('DocMaster');
        $session = $this->request->getSession()->read('Auth');
        $customer_id= $session->customer_id;
        $location_id= $session->base_location_id;
        if (!$this->Authorization->can($trainingCompliance, 'report')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $condition = [];
      //  $trainingMaster = $this->TrainingCompliance->TrainingMaster->get($training_master_id);
        $referenceNumberData=$this->request->getQuery('reference_number');
        $years = $this->request->getQuery("year");
        $task_name=$this->request->getQuery('task_name');
        $fromDate=$this->request->getQuery('from_date');
        $toDate=$this->request->getQuery('to_date');
        $departmentsID=$this->request->getQuery('departments_id');
        if($referenceNumberData != ""){ $condition["AND"][]=['OR'=>["TrainingBatch.batch_no Like '%$referenceNumberData%' "]]; }
        if($task_name != ""){ $condition["AND"][]=['OR'=>["TrainingMaster.training_name like '%$task_name%'"]]; }
        
        if($fromDate != ''){
            $condition['AND'][]="date(TrainingMaster.due_date) >='".$fromDate."'";
        }
        
        if($toDate != ''){
            $condition['AND'][]="date(TrainingMaster.due_date) <='".$toDate."'";
        }
        
        if($years == null || $years == ''){
            $years = date('Y');
        }
        if($years!=null && $years!=""){
            $condition["AND"][] = "YEAR(TrainingMaster.due_date)= $years";
        }
        if($departmentsID != ""){ $condition["AND"][]=['OR'=>["TrainingCompliance.department_id" => $departmentsID]]; }
        
        $this->paginate = [
            'contain' =>['Users'=>['Departments'],'Customer', 'TrainingMaster','Departments','TrainingBatch'],
            //'conditions' => [$condition],
            "conditions" => [$condition,'TrainingCompliance.status <' => 3,'TrainingCompliance.customer_id'=>$customer_id],
            
        ];
        
        $query = $this->Authorization->applyScope($this->TrainingCompliance->find(),'InProcessReport');
        
        $trainingComplience = $this->paginate($query);
        //debug($trainingComplience);die;
        foreach ($trainingComplience as $key => $tc) :
        $docmasterName = isset($tc->training_master->training_for_model_id) ? $tc->training_master->training_for_model_name : '';
        $docmasterId = isset($tc->training_master->training_for_model_id) ? $tc->training_master->training_for_model_id : '';
        if (isset($docmasterId) && $docmasterId != '' && $docmasterName == 'DocMaster' && $docmasterId != null) {
            $docMaster_id = $tc->training_master->training_for_model_id;
            if(isset($docMaster_id)){
                $docMaster = $this->DocMaster->find('all', [
                    "fields" => [
                        "current_revision_no",
                        "doc_no"
                    ]
                ])
                ->where([
                    'DocMaster.id' => $docMaster_id
                ])
                ->first();
                if (isset($docMaster)) {
                    $docData = array(
                        'docmaster_id' => $docMaster_id,
                        'current_revision_no' => $docMaster->current_revision_no,
                        'doc_no' => $docMaster->doc_no
                    );
                    $tc->training_master->docmaster = $docData;
                }
                
            }
           
        } else {
            continue;
        }
        endforeach ;
        
        $DepartmentsData= $this->Departments->find("list",['keyField'=>'id','valueField'=>'department'])->where(["customer_id"=>$customer_id,"active"=>1])->toArray();
        
        $this->set(compact('years','DepartmentsData','fromDate','trainingComplience','toDate','departmentsID','task_name','referenceNumberData','trainingReportIndex','session'));
       
        if ($this->request->getQuery('export') != null) {
            
            $this->loadModel('TrainingBatch');
            $this->loadModel('Departments');
            $this->loadModel('TrainingCompliance');
            $session = $this->request->getSession()->read('Auth');
            $customer_id= $session->customer_id;
            $location_id= $session->base_location_id;
           
            $condition = [];
            //  $trainingMaster = $this->TrainingCompliance->TrainingMaster->get($training_master_id);
            $referenceNumberData=$this->request->getQuery('reference_number');
            $task_name=$this->request->getQuery('task_name');
            $fromDate=$this->request->getQuery('from_date');
            $toDate=$this->request->getQuery('to_date');
            $departmentsID=$this->request->getQuery('departments_id');
            
            if($referenceNumberData != ""){ $condition["AND"][]=['OR'=>["TrainingBatch.batch_no Like '%$referenceNumberData%' "]]; }
            if($task_name != ""){ $condition["AND"][]=['OR'=>["TrainingMaster.training_name like '%$task_name%'"]]; }
            if($departmentsID != ""){ $condition["AND"][]=['OR'=>["TrainingCompliance.department_id" => $departmentsID]]; }
            
            if($fromDate != ''){
                $condition['AND'][]="date(TrainingMaster.due_date) >='".$fromDate."'";
            }
            
            if($toDate != ''){
                $condition['AND'][]="date(TrainingMaster.due_date) <='".$toDate."'";
            }
            $condition[]["AND"]=['TrainingCompliance.status <' => 3,'TrainingCompliance.customer_id'=>$customer_id];
            $trainingComplience = $this->Authorization->applyScope($this->TrainingCompliance->find('all',[
                'contain' =>['Users'=>['Departments'],'Customer', 'TrainingMaster','Departments','TrainingBatch']])
                ->where([$condition]),'InProcessReport')->toArray();
            
                foreach ($trainingComplience as $key => $tc) :
                $docmasterName = isset($tc->training_master->training_for_model_id) ? $tc->training_master->training_for_model_name : '';
                $docmasterId = isset($tc->training_master->training_for_model_id) ? $tc->training_master->training_for_model_id : '';
                if (isset($docmasterId) && $docmasterId != '' && $docmasterName == 'DocMaster' && $docmasterId != null) {
                    $docMaster_id = $tc->training_master->training_for_model_id;
                    if(isset($docMaster_id)){
                        $docMaster = $this->DocMaster->find('all', [
                            "fields" => [
                                "current_revision_no",
                                "doc_no"
                            ]
                        ])
                        ->where([
                            'DocMaster.id' => $docMaster_id
                        ])
                        ->first();
                        if (isset($docMaster)) {
                            $docData = array(
                                'docmaster_id' => $docMaster_id,
                                'current_revision_no' => $docMaster->current_revision_no,
                                'doc_no' => $docMaster->doc_no
                            );
                            $tc->training_master->docmaster = $docData;
                        }
                        
                    }
                    
                } else {
                    continue;
                }
                endforeach ;
                
                //debug($trainingComplience);die;
            $DepartmentsData= $this->Departments->find("list",['keyField'=>'id','valueField'=>'department'])->where(["customer_id"=>$customer_id,"active"=>1])->toArray();
            $conn = $this;
            $CallBackStream = new CallbackStream(function () use ($conn,$trainingComplience) {
                try {
                    $conn->viewBuilder()->setLayout("xls");
                    $conn->set(compact('trainingComplience'));
                    echo $conn->render('reportexcel');
                } catch (Exception $e) {
                    echo $e->getMessage();
                    $e->getTrace();
                }
            });
                return $this->response->withBody($CallBackStream)
                ->withAddedHeader("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
                ->withAddedHeader("Content-disposition", "attachment; filename=Trainining Compliance In ProcessReport.xls");
                
        }
     
    }
    
    
    public function completedReport()
    {
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingReportIndex = $trainingCompliance;
        $this->loadModel('TrainingBatch');
        $this->loadModel('Departments');
        $this->loadModel('DocMaster');
        $session = $this->request->getSession()->read('Auth');
        $customer_id= $session->customer_id;
        $location_id= $session->base_location_id;
        if (!$this->Authorization->can($trainingCompliance, 'report')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $condition = [];
        //  $trainingMaster = $this->TrainingCompliance->TrainingMaster->get($training_master_id);
        $referenceNumberData=$this->request->getQuery('reference_number');
        $user_name=$this->request->getQuery('user_name');
        $fromDate=$this->request->getQuery('from_date');
        $toDate=$this->request->getQuery('to_date');
        $departmentsID=$this->request->getQuery('departments_id');
        if($user_name != "")
        {
            $user_name=trim($user_name);
        }
        
        if($referenceNumberData != ""){ $condition["AND"][]=['OR'=>["TrainingBatch.batch_no Like '%$referenceNumberData%' "]]; }
        if($user_name != ""){ $condition["AND"][]=['OR'=>["Users.userfullname like '%$user_name%'"]]; }
       if($departmentsID != ""){ $condition["AND"][]=['OR'=>["TrainingCompliance.department_id" => $departmentsID]]; }
        if($fromDate == null)
        {
            $fromDate = "";
        }
        if($toDate == null)
        {
            $toDate = "";
        }
        
        if($fromDate != '' && $toDate != ''){
            $condition['AND'][]="date(TrainingCompliance.modified) >='".$fromDate."'";
        }
        
        if($fromDate != '' && $toDate != ''){
            $condition['AND'][]="date(TrainingCompliance.modified) <='".$toDate."'";
        }//debug($condition);
        $this->paginate = [
            'contain' =>['Users'=>['Departments'],'Customer', 'TrainingMaster','Departments','TrainingBatch'],
            //'conditions' => [$condition],
            "conditions" => [$condition,'TrainingCompliance.status' => 3,'TrainingCompliance.customer_id'=>$customer_id,'TrainingMaster.id IS NOT NULL'],
            
        ];
        $query = $this->Authorization->applyScope($this->TrainingCompliance->find(),'CompletedReport');
        $trainingComplience = $this->paginate($query);
         $DepartmentsData= $this->Departments->find("list",['keyField'=>'id','valueField'=>'department'])->where(["customer_id"=>$customer_id,"active"=>1])->toArray();
         
         foreach ($trainingComplience as $key => $tc) :
            $docmasterName = isset($tc->training_master->training_for_model_id) ? $tc->training_master->training_for_model_name : '';
            $docmasterId = isset($tc->training_master->training_for_model_id) ? $tc->training_master->training_for_model_id : '';
            if (isset($docmasterId) && $docmasterId != '' && $docmasterName == 'DocMaster' && $docmasterId != null) {
                $docMaster_id = $tc->training_master->training_for_model_id;
               if ($docMaster_id != null) {
                   $docMaster = $this->DocMaster->find('all', [
                       "fields" => [
                           "current_revision_no",
                           "doc_no"
                       ]
                   ])
                   ->where([
                       'DocMaster.id' => $docMaster_id
                   ])
                   ->first();
                  
                   if (isset($docMaster)) {
                       $docData = array(
                           'docmaster_id' => $docMaster_id,
                           'current_revision_no' => $docMaster->current_revision_no,
                           'doc_no' => $docMaster->doc_no
                       );
                       $tc->training_master->docmaster = $docData;
                   }
               }
            } else {
                continue;
            }
        endforeach ;
        $this->set(compact('DepartmentsData','fromDate','trainingComplience','toDate','departmentsID','user_name','referenceNumberData','trainingReportIndex','session'));
      
        if ($this->request->getQuery('export') != null) {
            
            $this->loadModel('TrainingBatch');
            $this->loadModel('Departments');
            $this->loadModel('TrainingCompliance');
            $session = $this->request->getSession()->read('Auth');
            $customer_id= $session->customer_id;
            $location_id= $session->base_location_id;
            
            $condition = [];
            //  $trainingMaster = $this->TrainingCompliance->TrainingMaster->get($training_master_id);
            $referenceNumberData=$this->request->getQuery('reference_number');
            $user_name=$this->request->getQuery('user_name');
            $fromDate=$this->request->getQuery('from_date');
            $toDate=$this->request->getQuery('to_date');
            $departmentsID=$this->request->getQuery('departments_id');
            
            if($referenceNumberData != ""){ $condition["AND"][]=['OR'=>["TrainingBatch.batch_no Like '%$referenceNumberData%' "]]; }
            if($user_name != ""){ $condition["AND"][]=['OR'=>["Users.userfullname like '%$user_name%'"]]; }
            if($departmentsID != ""){ $condition["AND"][]=['OR'=>["TrainingCompliance.department_id" => $departmentsID]]; }
            if($fromDate == null)
            {
                $fromDate = "";
            }
            if($toDate == null)
            {
                $toDate = "";
            }
            
            if($fromDate != '' && $toDate != ''){
                $condition['AND'][]="date(TrainingCompliance.modified) >='".$fromDate."'";
            }
            
            if($fromDate != '' && $toDate != ''){
                $condition['AND'][]="date(TrainingCompliance.modified) <='".$toDate."'";
            }
            $condition[]["AND"]=['TrainingCompliance.status' => 3,'TrainingCompliance.customer_id'=>$customer_id];
           $trainingComplience = $this->Authorization->applyScope($this->TrainingCompliance->find('all',[
                'contain' =>['Users'=>['Departments'],'Customer', 'TrainingMaster','Departments','TrainingBatch']])
                ->where([$condition]),'CompletedReport')->toArray();
            
                foreach ($trainingComplience as $key => $tc) :
                $docmasterName = isset($tc->training_master->training_for_model_id) ? $tc->training_master->training_for_model_name : '';
                $docmasterId = isset($tc->training_master->training_for_model_id) ? $tc->training_master->training_for_model_id : '';
                if (isset($docmasterId) && $docmasterId != '' && $docmasterName == 'DocMaster' && $docmasterId != null) {
                    $docMaster_id = $tc->training_master->training_for_model_id;
                    if ($docMaster_id != null) {
                        $docMaster = $this->DocMaster->find('all', [
                            "fields" => [
                                "current_revision_no",
                                "doc_no"
                            ]
                        ])
                        ->where([
                            'DocMaster.id' => $docMaster_id
                        ])
                        ->first();
                        
                        if (isset($docMaster)) {
                            $docData = array(
                                'docmaster_id' => $docMaster_id,
                                'current_revision_no' => $docMaster->current_revision_no,
                                'doc_no' => $docMaster->doc_no
                            );
                            $tc->training_master->docmaster = $docData;
                        }
                    }
                } else {
                    continue;
                }
                endforeach ;
                
                $DepartmentsData= $this->Departments->find("list",['keyField'=>'id','valueField'=>'department'])->where(["customer_id"=>$customer_id,"active"=>1])->toArray();
                $conn = $this;
                $CallBackStream = new CallbackStream(function () use ($conn,$trainingComplience) {
                    try {
                        $conn->viewBuilder()->setLayout("xls");
                        $conn->set(compact('trainingComplience'));
                        echo $conn->render('report_complete_excel');
                    } catch (Exception $e) {
                        echo $e->getMessage();
                        $e->getTrace();
                    }
                });
                    return $this->response->withBody($CallBackStream)
                    ->withAddedHeader("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
                    ->withAddedHeader("Content-disposition", "attachment; filename=Trainining Compliance CompletedReport.xls");
                    
        }
        
    }
    

    function updateAttendance() {
        $this->loadModel('Tc.TrainingCompliance');
        $this->loadModel('Tc.TrainingComplianceLog');
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        if (!$this->Authorization->can($trainingCompliance, 'report')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        if ($this->request->is(['patch', 'post', 'put'])) {
            $PostData = $this->request->getData(); //debug($PostData);die;
            $complianceid = isset($PostData['id'])?$PostData['id']:'';
            if(isset($PostData['is_present']))
            {
                if ($PostData['is_present'] == "Present") {
                    $PostData['is_present'] = 1;
                   }
                else {
                    $PostData['is_present'] = 0;
                }
            }
            if ($complianceid && !empty($complianceid)) {
                
                $trainingCompliance= $this->TrainingCompliance->get($complianceid, [
                    'contain' => [
                        'TrainingComplianceAttachment',
                        'TrainingComplianceLog' => ['StatusChangeByUser']
                    ],
                ]);
                 $trainingComp = $this->TrainingCompliance->patchEntity($trainingCompliance, $PostData,['associated'=>['TrainingComplianceLog']]);
               //debug($trainingComp);die;
                 $res = $this->TrainingCompliance->save($trainingComp);
                 if ($res) {
                         $this->Flash->success(__('The training Attendance has been saved.'));
                         $this->redirect($this->referer());
                     }
                     else
                     {
                         $this->Flash->error(__('The training Attendance could not be saved. Please, try again.'));
                         $this->redirect($this->referer());
                     }
                 }
                
            }
           
        }

    public function deleteDocEvidence($attach_id=null,$doc_id=null,$capa_status_id=null,$next_status_id=null,$previous_status_id=null){
        $doc_id=($doc_id==null)?null:decryptVal($doc_id);
        $attach_id=($attach_id==null)?null:decryptVal($attach_id);
        $this->loadModel("DocMaster");
        $DocMaster= $this->DocMaster->find("all")->where([
            "id"=>$doc_id
        ]
            );
        $this->Authorization->skipAuthorization();
        
        $this->loadModel("DocTrainingEvidence");
        $attachment=$this->DocTrainingEvidence->get($attach_id);
        $customer_id=$this->request->getSession()->read("Auth")->get("customer_id");
        if(!empty($attachment)){
            $doc_id=$attachment['doc_master_id'];
            if(trim($attachment['file_name'])!=""){
                $filesPathNew="doc_training_evidence/".$doc_id.DS.$attachment['file_name'];
                
                if(QMSFile::delete($filesPathNew,$customer_id)){
                    //debug(QMSFile::delete($filesPathNew,$customer_id));die;
                    //$attachfileDel = $this->AuditAttachments->get($id);
                    if ($this->DocTrainingEvidence->delete($attachment)) {
                        $this->Flash->success(__('The Doc training evidence has been deleted.'));
                    } else {
                        $this->Flash->error(__('The training evidence could not be deleted. Please, try again.'));
                    }
                }else{
                    // debug(QMSFile::delete($filesPathNew,$customer_id));die;
                    $this->Flash->error(__('The training evidence could not be deleted. Please, try again.'));
                    
                }
                
            }
            //return $this->redirect(['action' => 'index',encryptVal($capa_id),$capa_status_id,$next_status_id,$previous_status_id,]);
            return  $this->redirect($this->referer());
        }
        //return $this->redirect(['action' => 'index']);
    }

    function getLastLog($docMaster=null,$previousStepId=null){

        if($docMaster){
            $previouseStatusLog =array_filter($docMaster['doc_status_log'], function($v,$k) use ($previousStepId){
                if($v->doc_status_master_id==$previousStepId && $v->step_completed==1 ){
                    return $v;
                }
            },ARRAY_FILTER_USE_BOTH);
                $nextActionBy = end($previouseStatusLog)->next_action_by_user;
                return $nextActionBy;
        }
    }
    
    function trainingByRole()
    {
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        $customer_id=$session['customer_id'];
        $location_id= $session['base_location_id'];
        $group_id=$this->request->getSession()->read('Auth.groups_id');
        if (!$this->Authorization->can($trainingCompliance, 'ByRole')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $this->loadModel('FunctionalRoles');
        $search_customer_id=$this->request->getQuery('search_customer_id');
        $data['status'] = 1;
        if ($this->request->is('post')) {
            $data=$this->request->getData(); //debug($data);die;
            $functionalRole = $this->FunctionalRoles->newEmptyEntity();
            $functionalRole = $this->FunctionalRoles->patchEntity($functionalRole, $data);
            $functionalRoleResult=$this->FunctionalRoles->save($functionalRole);
            if ($functionalRoleResult) {
                $this->Flash->success(__('The functional role has been saved.'));
                return $this->redirect(['action' => 'trainingByRole']);
                //return $this->redirect($this->request->getSession()->read('lastPages'));
            }else{
                $this->Flash->error(__('The functional role could not be saved.'));
                $this->Flash->error(__('The functional role could not be saved.'));
            }
        }
        
            $conditions=array("customer_id"=>$customer_id);
            $this->paginate = [
                'conditions'=>$conditions,
                "order"=>['id'=>'asc'],
            ];
            
            $trainingRolesCountQuery = $this->FunctionalRoles->find()
            ->select([
                'FunctionalRoles.id',
                'FunctionalRoles.role_name',
                'TrainingRoleCount' => $this->FunctionalRoles->query()
                ->func()
                ->count('TrainingRoles.functional_role_id'),
            ])
            ->leftJoinWith('TrainingRoles')
            ->where(['FunctionalRoles.status' => 1])
            ->group(['FunctionalRoles.id']);
            
            if (!empty($search_customer_id)) {
                $trainingRolesCountQuery->where(['FunctionalRoles.customer_id' => $search_customer_id]);
            }
            
            $trainingRoles = $this->paginate($trainingRolesCountQuery);

            $this->loadModel("Users");
            
            $this->loadModel("Customer");
            $query = $this->Users->find();
            $query->select(['functional_role_id', 'count' => $query->func()->count('id')]);
            $query->group(['functional_role_id']);
            $query->where(['Users.active'=>1]);
            $roleUsers = $query->toArray();
            //create a list so you dont have to use a for loop on the view page
            $rolesUsersList = [];
            foreach($roleUsers as $key=>$value){
                if($value['functional_role_id'] != null) {
                $rolesUsersList[$value['functional_role_id']] = $value['count'];
                }
            }

            $customer= $this->Customer->find("list", ['keyField' => 'id','valueField' => 'company_name'])->toArray();
            $this->getTransPass();
            
        $this->set(compact('trainingRoles','group_id','customer','search_customer_id','customer_id','location_id','rolesUsersList'));
        
    }
    
    public function assignTrainings($role_id=null)
    {
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $this->loadModel('FunctionalRoles');
        $role_id=($role_id==null)?null:decryptVal($role_id);
        
        $functionalRole = $this->FunctionalRoles->get($role_id, [
            'contain' => [],
        ]);
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('Tc.TrainingRoles');
        $this->loadModel('Tc.TrainingCompliance');
        $this->loadModel('Users');
        
        $search_customer_id=$this->request->getQuery('search_customer_id');
        
        if (!$this->Authorization->can($trainingCompliance, 'index')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        $customer_id=$this->request->getSession()->read('Auth.customer_id');
        $location_id=$this->request->getSession()->read('Auth.base_location_id');
        $group_id=$this->request->getSession()->read('Auth.groups_id');
        
        if ($this->request->is(['patch', 'post', 'put'])) {
            $saveData = $this->request->getData(); 
            $role_id = $saveData['role_id'];
            
            $verify = $this->TrainingRoles->find('list', ['keyField' => 'id', 'valueField' => 'training_master_id'])->where(['functional_role_id' => $role_id])->toArray();
           
            $training_roles = !empty($saveData['training_roles'])?$saveData['training_roles']:[];
            $Filterresult   = array_diff($verify,$training_roles);
          
            
            $trainingRolesData=[];
            foreach ($training_roles as $k=>$trainingRole)
            {
                $isPresent = $this->TrainingRoles->find('all')->where(['training_master_id'=>$trainingRole,'functional_role_id' => $role_id])->first();
                if (!$isPresent) {
                    $trainingRolesData[$k]['training_master_id'] = $trainingRole;
                    $trainingRolesData[$k]['functional_role_id'] = $role_id;
                }
                else {
                    continue;
                }
                
            }
           
          //added by Atish.
          if(!empty($training_roles)){ 
             
            $trainingMasterData = $this->TrainingMaster->find('all',['fields' =>["id","due_date","type",'selected_department']])->where(['TrainingMaster.id IN' => $training_roles ])->toArray();
            } else{  $trainingMasterData = []; }  
           
            $usersData = $this->Users->find('all', ['fields'=>["id","departments_id","functional_role_id"]])->where(['functional_role_id' => $role_id])->toArray();

            $trainingComplianceData = [];
          
            if(!empty($Filterresult)){ 
            $trainingMasterRemoveData = $this->TrainingMaster->find('all',['fields' =>["id",'selected_department']])->where(['TrainingMaster.id IN' => $Filterresult ])->toArray();
          
            }
            $UserdepinArray = [];
            $Userdep = [];
            foreach($usersData as $keyUser=>$valueUser){
                
                $tcIds = [];
                $Userdep = [];
                $UserdepinArray = [];
                if(!empty($trainingMasterRemoveData)){ 
                foreach($trainingMasterRemoveData as $keydhdhhf=>$valuedf){
                    //debug($valuedf['selected_department']);die;
                    if(!empty($Filterresult)){
                        foreach($Filterresult as $FilterresultKey=>$FilterresultValue){
                            if(isset($valuedf['selected_department']) && $valuedf['selected_department'] !='""' && $valuedf['id'] == $FilterresultValue && in_array($valueUser['departments_id'] , json_decode($valuedf['selected_department']))){
                            }else{
                                $trainComplianceDelete = $this->TrainingCompliance->find('list', ['keyField' => 'id', 'valueField' => 'training_id'])->where(['user_id IN'=>$valueUser['id'],'training_id IN'=>$Filterresult])->toArray();
                                $trainComplianceIDs = array_keys(array_diff($trainComplianceDelete ,$training_roles));
                            }
                        }
                        
                    }
                }
                }
                
                foreach($trainingMasterData as $keyTrainingRole => $valueTrainingRole){
                    
                    $trainCompliance = $this->TrainingCompliance->find('all',['fields' =>["id","training_id" , "user_id" ,"is_for_current_role_or_dept" ],'disabledBeforeFind'=>true])->where(['training_id' =>$valueTrainingRole['id'] , 'user_id'=>$valueUser['id']])->first();
                     $isForCurrentRoleOrDept =  isset($trainCompliance['is_for_current_role_or_dept'])?$trainCompliance['is_for_current_role_or_dept']:'';
                      if($isForCurrentRoleOrDept == 0){ $tcIds[] =  $trainCompliance['id']; }
                        if(empty($trainCompliance)){   
                        if($valueTrainingRole['type'] == 1){$status = 0;}elseif($valueTrainingRole['type'] == 2){$status = 1; }else{$status = NULL;}
                            $trainingComplianceData[] = [
                                "customer_id"  =>  $customer_id,
                                'training_id'  => $valueTrainingRole['id'],
                                'status'       => $status,
                                'is_special'   => 0,
                                'is_active'    => 'Y',
                                'due_date'     => new FrozenTime($valueTrainingRole['due_date']),
                                'user_id'      => $valueUser['id'],
                                'department_id'=> $valueUser['departments_id'],
                                'role_id'      => $valueUser['functional_role_id']
                            ];
                        }
                }

                if(!empty($tcIds)){$this->TrainingCompliance->updateAll(['is_for_current_role_or_dept'=>1],['TrainingCompliance.id IN'=>$tcIds]);}
                if(!empty($trainComplianceIDs)){$this->TrainingCompliance->updateAll(['is_for_current_role_or_dept'=>0],['TrainingCompliance.id IN'=>$trainComplianceIDs]);}
                
            }

            if (!empty($Filterresult)) {
                $result = $this->TrainingRoles->deleteAll(['training_master_id IN'=>$Filterresult,'functional_role_id'=>$role_id]);
            }
            if(!empty($trainingComplianceData)){ 
                $TrainingComplianceData = $this->TrainingCompliance->newEntities($trainingComplianceData);
                $trainingComplianceData = $this->TrainingCompliance->patchEntities($TrainingComplianceData, $trainingComplianceData);            
                $result  = $this->TrainingCompliance->saveMany($trainingComplianceData);
            }        
            //ended. 

            $traningData = $this->TrainingRoles->newEntities($trainingRolesData);
            $trainingRoles = $this->TrainingRoles->patchEntities($traningData, $trainingRolesData);
            
           
            if ($this->TrainingRoles->saveMany($trainingRoles) || $result) {
                $this->Flash->success(__('The functional role training has been saved.'));
                return $this->redirect($this->request->getSession()->read('lastPages'));
            }else{
                $this->Flash->error(__('The functional role training could not be saved.'));
                return $this->redirect($this->request->getSession()->read('lastPages'));
            }
            
           
        } 
        $query = $this->TrainingMaster->find('list', ['keyField' => 'id', 'valueField' => 'training_name']);
        $assignedTrainings = $query->matching('TrainingRoles', function ($q) use ($role_id) {
            return $q->where(['TrainingRoles.functional_role_id' =>$role_id,'TrainingMaster.active'=>1]);
        })->toArray();
        
        $allTrainings = $this->TrainingMaster->find('list', ['keyField' => 'id', 'valueField' => 'training_name'])
        ->notMatching('TrainingRoles', function ($q) use ($role_id) {
            return $q->where(['TrainingRoles.functional_role_id' => $role_id,'TrainingMaster.active'=>1]);
        })->toArray();
        
        $this->getTransPass();
        
        $this->set(compact('functionalRole','assignedTrainings','allTrainings'));
    }
    
    function trainingByEmployee()
    {
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        $loggedUserId=$session['id'];
        $customer_id=$session['customer_id'];
        $location_id= $session['base_location_id'];
        $group_id=$this->request->getSession()->read('Auth.groups_id');
        if (!$this->Authorization->can($trainingCompliance, 'ByEmployee')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $search_customer_id=$this->request->getQuery('search_customer_id');
        
        $this->loadModel('FunctionalRoles');
        $this->loadModel('Users');
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('Tc.TrainingRoles');
        $this->loadComponent('Common');
        
        if ($this->request->is(['patch', 'post', 'put'])) {
            $saveData = $this->request->getData(); //debug($saveData);die;
            
            
            $result='';
            $savedTrainings='';
            $user_id = $saveData['user_id'];
            
            $user = $this->Users->find('all',['fields'=>['id','departments_id','functional_role_id','userfullname']])->where(['id'=>$user_id])->first();
            
            if (isset($saveData['training_roles']) && !empty($saveData['training_roles'])) {
                
                $trainingComplianceData=[];
                foreach ($saveData['training_roles'] as $key=>$newTraining)
                {
                    $exists = $this->TrainingCompliance->exists(['user_id'=>$saveData['user_id'],'training_id'=>$newTraining]);
                    $is_mandatory = $this->TrainingMaster->exists(['id' => $newTraining,'is_mandatory'=>1]);
                    $TrainingMaster = $this->TrainingMaster->find()->select(['id','type','due_date','complete_in_days'])->where(['id'=>$newTraining])->first();
                    
                    if ($exists === false) {
                        
                        $trainingComplianceData[$key] = [
                            'customer_id' => $customer_id,
                            'training_id' => $newTraining,
                            'user_id' => $saveData['user_id'],
                            'created_by' => $loggedUserId,
                            'department_id' =>$user->departments_id,
                            'role_id' => $user->functional_role_id,
                            'is_mandatory' => $is_mandatory?1:0
                        ];
                        
                        if ($TrainingMaster->type == 2) {
                            $trainingComplianceData[$key]['status'] = 1;
                        }
                        else {
                            $trainingComplianceData[$key]['status'] = 0;
                        }
                        
                        if ($TrainingMaster->complete_in_days > 0) {
                            $currentTimestamp = time();
                            $newTimestamp = $currentTimestamp + $TrainingMaster->complete_in_days * 24 * 60 * 60;
                            $duedate = date('Y-m-d', $newTimestamp);
                            $trainingComplianceData[$key]['due_date'] = new FrozenTime($duedate);
                        }
                        else{
                            $trainingComplianceData[$key]['due_date'] = new FrozenTime($TrainingMaster->fduedate);
                        }
                        
                    }
                }
                $traningData = $this->TrainingCompliance->newEntities($trainingComplianceData);
                $trainingCompliance = $this->TrainingCompliance->patchEntities($traningData, $trainingComplianceData);
                //$savedTrainings = $this->TrainingCompliance->saveMany($trainingCompliance);
                
                foreach ($trainingCompliance as $compliance)
                {
                    $savedTrainings = $this->TrainingCompliance->save($compliance);
                }
                
                $removedTrainings = $this->TrainingCompliance->find('all',['fields'=>['id']])->where(['TrainingCompliance.training_id NOT IN' => $saveData['training_roles'],'TrainingCompliance.user_id'=>$saveData['user_id']])->toArray();
                
                if (isset($removedTrainings)) {
                    $remtrain=[];
                    foreach ($removedTrainings as $key=>$remTraining)
                    {
                        $remtrain[$key]=$remTraining->id;
                    }
                    if (!empty($remtrain)) {
                        foreach($remtrain as $Comp) {
                            $compdata = array();
                            $cid = (int) $Comp;
                            $traningData = $this->TrainingCompliance->get($cid);
                            $compdata['is_for_current_role_or_dept'] = 0;
                            $trainingCompliance = $this->TrainingCompliance->patchEntity($traningData, $compdata);
                            $result = $this->TrainingCompliance->save($trainingCompliance);
                        }
                    }
                    
                }
                
                
            }
            else if(isset($saveData['isRemoveAll']) && $saveData['isRemoveAll'] == 1){
                $removedAllTrainings = $this->TrainingCompliance->find('all',['fields'=>['id']])->where(['TrainingCompliance.user_id'=>$saveData['user_id']])->toArray();
                foreach($removedAllTrainings as $CompTrain) {
                    $compdata = array();
                    $cid = (int) $CompTrain->id;
                    $traningData = $this->TrainingCompliance->get($cid);
                    if($traningData->status == 0)
                    {
                        $result = $this->TrainingCompliance->deleteAll(['id'=>$cid]);
                    }
                    else {
                        $compdata['is_for_current_role_or_dept'] = 0;
                        $trainingCompliance = $this->TrainingCompliance->patchEntity($traningData, $compdata);
                        $result = $this->TrainingCompliance->save($trainingCompliance);
                    }
                    
                }
            }
             $update_role='';
            if(isset($saveData['functional_role_id']) && !empty($saveData['functional_role_id']))
            {
                $newRole_id = $saveData['functional_role_id'];
                $userdetails = $this->Users->find('all', array(
                    'contain' => array('Departments'),
                    'fields' => array('Users.functional_role_id', 'Users.departments_id'),
                ))->where(['Users.id'=>$user_id])->toArray();
                
                $userdata = !empty($userdetails)?$userdetails[0]:'';
                $oldRole_id = $userdata->functional_role_id;
                $userdepartmentid = $userdata->departments_id;
                
                $update_role= $this->Users->updateAll(array('functional_role_id' => $newRole_id), array('id' => $user_id));
                
                if((int)$newRole_id != $oldRole_id && !empty($oldRole_id))
                {
                    
                    $this->loadModel('TrainingCompliance');
                    $userData = $this->TrainingCompliance->find('all',['fields'=>['id']])->where(['user_id'=>$user_id,'role_id'=>$oldRole_id,'status <='=>1])->toArray();
                    $Delcompliances='';
                    if (isset($userData) && $userData !='') {
                        foreach ($userData as $k=>$userdt)
                        {
                            $Delcompliances=$this->TrainingCompliance->updateAll(['TrainingCompliance.is_for_current_role_or_dept'=>0],['TrainingCompliance.id' => $userdt->id]);
                        }
                        
                    }
                    
                    $this->Common->assignTrainings($user_id,$newRole_id,$userdepartmentid,$customer_id);
                }
                //                 else
                //                 {
                
                //                     $this->Common->assignTrainings($user_id,$newRole_id,$userdepartmentid,$customer_id);
                //                 }
                
                
                
            }
            
           
           if ($result || $savedTrainings || $update_role) {
                $this->Flash->success(__('The employee training has been saved.'));
                return $this->redirect(['action' => 'trainingByEmployee']);
            }
            else{
                $this->Flash->error(__('The employee training could not be saved.'));
                return $this->redirect(['action' => 'trainingByEmployee']);
            }
            
            
        }
        
        $isTrainingCoordinator = false;
        $isTrainingHead = false;
        $access_module = $session->customer_role->rolewise_permissions;
        
        foreach($access_module as $rows){
            if(isset($rows['plugins_module']['plugin']))
            {
                if($rows['plugins_module']['plugin']=="Tc" && $rows['plugins_module']['element_name']=="" && $rows['plugins_module']['controller']=="TrainingBatch" && $rows['plugins_module']['method']=="IsTrainingCoordinator" && $rows['can_access']==1 && $rows['can_create']==1){
                    $isTrainingCoordinator = true;
                }
                
            }
            
            if(isset($rows['plugins_module']['plugin']))
            {
                if($rows['plugins_module']['plugin']=="Tc" && $rows['plugins_module']['element_name']=="" && $rows['plugins_module']['controller']=="TrainingMaster" && $rows['plugins_module']['method']=="IsTrainingHead" && $rows['can_access']==1 && $rows['can_create']==1){
                    $isTrainingHead = true;
                }
                
            }
            
        }
        
        $userConditions=['Users.customer_id'=>$customer_id,'Users.base_location_id'=>$location_id,'Users.active'=>1,'Users.del_status'=>1];
        
        if ($isTrainingCoordinator) {
            $departnemt_cond = array('Users.departments_id'=>$session->departments_id,'Departments.active'=>1);
            $userConditions =  array_merge($userConditions,$departnemt_cond);
            
        }
        
        if ($isTrainingHead) {
            $departnemt_cond = array('Departments.active'=>1);
            $userConditions =  array_merge($userConditions,$departnemt_cond);
        }
        
        
        $managersUsers = $this->Common->getUsers($userConditions)->toArray();
        
        $functionalRole = $this->FunctionalRoles->find('list', ['keyField' => 'id', 'valueField' => 'role_name'])->where(['customer_id'=>$customer_id,'customer_location_id'=>$location_id])->toArray();
        $this->getTransPass();
        $this->set(compact('functionalRole','managersUsers',));
    }
    
    function trainingPlan()
    {
        
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingIndex = $trainingCompliance;
        $session = $this->request->getSession()->read('Auth');
        $customer_id=$session['customer_id'];
        $location_id= $session['base_location_id'];
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('Users');
        $this->loadModel('CustomerLocations');
        $this->loadComponent('Common');
        $condition = [];
        $isTrainingCoordinator = false;
        $access_module = $session->customer_role->rolewise_permissions;
         
                foreach($access_module as $rows){
                    if(isset($rows['plugins_module']['plugin']))
                        {
                            if($rows['plugins_module']['plugin']=="Tc" && $rows['plugins_module']['element_name']=="" && $rows['plugins_module']['controller']=="TrainingBatch" && $rows['plugins_module']['method']=="IsTrainingCoordinator" && $rows['can_access']==1 && $rows['can_create']==1){
                                $isTrainingCoordinator = true;
                            }
                                   
                        }
        
                }
                
              
        $TrainingMastercondition = [];
        if (!$this->Authorization->can($trainingCompliance, 'TrainingPlan')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $getListBy= $this->request->getQuery('getlist');
        $getDueDate = $this->request->getQuery('duedate');
        $getUser = $this->request->getQuery('getUser');
       // debug($getListBy);die;
        if(empty($getListBy) || $getListBy == null){
            $getListBy = $session['departments_id'];
        }
        if(empty($getDueDate)){ $dueDate = date('Y');  }else{ $dueDate = $getDueDate; }

        if (!empty($getListBy)) {
            if(empty($getUser)){
                $condition = array_merge($condition, ['Users.departments_id' => $getListBy]);
            }else{
            $condition = array_merge($condition, ['Users.departments_id' => $getListBy,'Users.id' => $getUser]);
            }
            $selectedDepartmentValue = '"' . $getListBy . '"';
            $TrainingMastercondition = "JSON_CONTAINS(TrainingMaster.selected_department, '\"$getListBy\"')";
            $crossinstructerCondtion = " NOT JSON_CONTAINS(TrainingMaster.selected_department, '\"$getListBy\"')";
        } 
        else if($isTrainingCoordinator) {
            if(empty($getUser)){
            $condition = array_merge($condition, ['Users.departments_id' => $session->departments_id]);
            }else{
                $condition = array_merge($condition, ['Users.departments_id' => $session->departments_id,'Users.id' => $getUser]);
            }
            $TrainingMastercondition = "JSON_CONTAINS(TrainingMaster.selected_department, '\"$session->departments_id\"')";
            // $crossinstructerCondtion = " NOT JSON_CONTAINS(TrainingMaster.selected_department, '\"$getListBy\"')";
        }
        else {
            if(empty($getUser)){
                $condition = array_merge($condition, ['Users.departments_id' => 1]);
            }else {
                if(empty($getListBy)){
                    $condition = array_merge($condition, ['Users.id' => $getUser]);
                }else{
                      $condition = array_merge($condition, ['Users.departments_id' => 1,'Users.id' => $getUser]);
                }
            
            }
            $TrainingMastercondition = "JSON_CONTAINS(TrainingMaster.selected_department, '\"1\"')";
           // $crossinstructerCondtion = " NOT JSON_CONTAINS(TrainingMaster.selected_department, '\"$getListBy\"')";
        }
        
        $trainingMasterIds = $this->TrainingMaster->find()
        ->select(['id'])
        ->where(['customer_id' => $session['customer_id'], 'customer_location_id' => $location_id])
        ->toArray();
        
        $trainingMasterIds = array_map(function ($entity) {
            return $entity->id;
        }, $trainingMasterIds);
      
        $userlist = $this->Users->find('all',['contain'=>['Departments',
            'TrainingCompliance'=>function($q) use($dueDate){
                return  $q->contain(['TrainingMaster'=>['fields'=>['due_date','id','active','deactivate_date','type']]])
                ->where(['YEAR(TrainingCompliance.due_date)' => $dueDate]);
                } ,
            'TrainingBatch'=>['fields'=>['id', 'training_master_id', 'instructor','from_date','to_date','status'],
            'TrainingCompliance'=>function($tq) use($dueDate) {
                return  $tq->select(['due_date','id','training_id','training_batch_id','is_present','completed_date','user_id'])->order(['completed_date' => 'ASC']) 
                ->where(['YEAR(TrainingCompliance.due_date)' => $dueDate]);
                } 
        ]],
        ])->where([$condition,
            'OR' => [
                'YEAR(Users.deactivate_date) =' => $dueDate,
                'AND' => [
                    'OR' => [
                        'YEAR(Users.deactivate_date) >=' => $dueDate,
                        'Users.deactivate_date IS NULL'
                    ],
                    'YEAR(Users.created) <=' => $dueDate,
                ],
            ]
            
        ]);
//         $conditions["OR"] = [
        //                             'YEAR(Users.deactivate_date) =' => $year,
        //                             'AND' => [
            //                                 'YEAR(Users.deactivate_date) >=' => $year,
            //                                 'YEAR(Users.created) <=' => $year,
            
            //                             ],
            
        //                             "Users.deactivate_date IS NULL"
        //                         ];
            
           
       
        $crossinstructer = $this->Users->find('all', [
            'contain' => ['Departments',
                'TrainingBatch' => [
                    'fields' => ['id', 'training_master_id', 'instructor', 'from_date', 'to_date', 'status'],
                    'TrainingMaster' => [
                        'fields' => ['id', 'due_date', 'training_name','selected_department'],
                       // 'conditions' => [$crossinstructerCondtion] 
                    ],
                    'TrainingCompliance'=>['fields'=>['id','training_id','training_batch_id','is_present','completed_date','user_id']]
                ]
            ]
        ])->toArray();
       
        if (!empty($userlist)) {
            $userTrainingIds = [];
            foreach ($userlist as $user) {
                foreach ($user->training_compliance as $compliance) {
                    $userTrainingIds[] = $compliance->training_id;
                }
            }
           
            $userTrainingIds = array_unique($userTrainingIds); 
            //debug($getListBy);debug($dueDate);die;
            $trainingMaster = [];
           if(!empty($userTrainingIds)){ 
               $trainingMaster = $this->TrainingMaster->find('all', ['fields' => ['id', 'training_name', 'reference_number', 'type', 'due_date', 'active', 'deactivate_date', 'deactivate_reason']])
               ->where([
                  'id IN' => $trainingMasterIds,
                   'YEAR(TrainingMaster.due_date) =' => $dueDate,
                   'OR' => [
                       'JSON_CONTAINS(TrainingMaster.selected_department, \'\"' . $getListBy . '\"\' )',
                       //'id IN' => $userTrainingIds
                   ]
               ])
               ->toArray();
               
          }
        }

        //debug($trainingMaster);die;
       // debug($crossinstructer);die;
        //$userlist = $this->Users->find('all',['contain'=>['Departments','TrainingCompliance']])->where(['Users.active'=>1,$condition])->toArray();
       //$trainingMaster = $this->TrainingMaster->find('list', ['keyField' => 'id', 'valueField' => 'training_name'])->where(['customer_id' => $session['customer_id'],'customer_location_id'=>$location_id,$TrainingMastercondition])->toArray();
       // $trainingMaster = $this->TrainingMaster->find('all', ['fields' => ['id','training_name','reference_number','type','due_date','active','deactivate_date','deactivate_reason']])->where(['customer_id' => $session['customer_id'],'customer_location_id'=>$location_id,$TrainingMastercondition])->toArray();
         $CustomerLocations = $this->CustomerLocations->find('list', ['keyField' => 'id', 'valueField' => 'name'])->where(['customer_id' => $session['customer_id']])->toArray();
        $departments = $this->Common->getDepartmentList($customer_id);
        
        //$userConditions1=['Users.customer_id'=>$customer_id,'Users.base_location_id'=>$location_id,'Users.active'=>1,'Users.del_status'=>1];
       
        
        //$managersUsersList = $this->Common->getUsers()->toArray();
        
        $managersUsersList = $this->Users->find('list',['keyField' => 'id','valueField' => 'userfullname',
            'conditions'=>['customer_id =' =>$session['customer_id'],
            ]]);
        $managersUsersList = $this->Authorization->applyScope($managersUsersList,'employeetrainingreport')->toArray();
       
        $customers = $this->TrainingCompliance->Customer->find('list', ['keyField' => 'id', 'valueField' => 'company_name'])->where(['Customer.active' => 1])->toArray();
        $userlistexport = $userlist->toArray();
        $userlist = $this->paginate($userlist);
        $this->set(compact('getListBy','getUser','managersUsersList','crossinstructer','departments','userlist','CustomerLocations', 'customers', 'trainingMaster',  'trainingCompliance', 'trainingIndex','userlistexport','dueDate'));
        
        if ($this->request->getQuery('export') != null) {
            $conn = $this;
            $CallBackStream = new CallbackStream(function () use ($conn,$userlistexport) {
                try {
                    $conn->viewBuilder()->setLayout("xls");
                    $conn->set(compact('userlistexport'));
                    echo $conn->render('trainingplanexcel');
                } catch (Exception $e) {
                    echo $e->getMessage();
                    $e->getTrace();
                }
            });
                return $this->response->withBody($CallBackStream)
                ->withAddedHeader("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
                ->withAddedHeader("Content-disposition", "attachment; filename=Trainining Plan.xls");
                
        }

        if ($this->request->getQuery('exportpdf') != null) {
            $pdfCon = new PdfController();
            $this->viewBuilder()->setLayout("blank");
            $this->set(compact('userlistexport'));
            $html = $this->render('trainingplanexcel');
            $pdfCon->exportToPDF($html, 'Trainining Plan');                
        }
        
       
    }
    
    function trainingInvestigation()
    {
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        $customer_id=$session['customer_id'];
        $location_id= $session['base_location_id'];
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('Users');
        $this->loadModel('CustomerLocations');
        $this->loadComponent('Common');
//         $condition = [];
//         $TrainingMastercondition = [];
        if (!$this->Authorization->can($trainingCompliance, 'Tni')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        $this->set(compact('customer_id','location_id'));
    }
    
    function getTransPass()
    {
        $isTransPassword = CustomerCache::read("transactional_password_required");
        if ($isTransPassword == 'Y') {
            $transPass  = 1;
        }
        else {
            $transPass = 0;
        }
        $this->set(compact('transPass'));
    }
    
    
    public function generateAttendanceSheet($slot_id=null,$batch_id=null,$training_id=null,$type="blank") {       
        $slot_id = ($slot_id == null) ? null : decryptVal($slot_id);
        $batch_id = ($batch_id == null) ? null : decryptVal($batch_id);
        $training_id = ($training_id == null) ? null : decryptVal($training_id);
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $timeViewFormat=CustomerCache::read("timeviewformat");
        $this->loadModel('Tc.TrainingBatch');
        $this->loadModel('Tc.TrainingSessionSlots');
        
        
        $this->loadModel('DocMaster');
        $session = $this->request->getSession()->read('Auth');
        //$template='attendancepdf';
        //$template='attendancepdf';
        $customer_id= $session->customer_id;
        $location_id= $session->base_location_id;
        $loggedUserName = $session->userfullname;
        if (!$this->Authorization->can($trainingCompliance, 'attendanceSheet')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        if($type == "blank") {
            $trainingSlotData = $this->TrainingSessionSlots->find('all',
            ['contain'=>[
                'TrainingSlotAttendance',
                'TrainingBatch'=>['Instructor','TrainingCompliance'=>['Users'=>[
                    'fields' => ["userfullname", "emp_id"],
                    'Departments']]],
                'TrainingMaster'=>["TrainingTypeSubtypeMaster"=>["ChildTrainingTypeSubtypeMaster"]]]
                ])->where(['TrainingSessionSlots.id'=>$slot_id])
            ->toArray();
        } else {
        $trainingSlotData = $this->TrainingSessionSlots->find('all', [
            'contain' => [
                'TrainingSlotAttendance' => [
                    'Users' => [
                        'fields' => ["userfullname", "emp_id"],
                        'Departments'
                    ],
                ],
                'TrainingBatch' => ['Instructor', 'TrainingCompliance','Externalinstructor'],
                'TrainingMaster' => [
                    'TrainingTypeSubtypeMaster' => ['ChildTrainingTypeSubtypeMaster']
                ]
            ]
        ])->where(['TrainingSessionSlots.id' => $slot_id])->toArray();
        }
        $docData = '';
        $docmasterName = isset($trainingSlotData[0]->training_master->training_for_model_id)?$trainingSlotData[0]->training_master->training_for_model_name:'';
        $docmasterId = isset($trainingSlotData[0]->training_master->training_for_model_id)?$trainingSlotData[0]->training_master->training_for_model_id:'';
        $docNum ='';
        $docRevNo ='';
        if (isset($docmasterId) && $docmasterName == 'DocMaster' || $docmasterName == 'Doc') {
            $docMaster_id = $trainingSlotData[0]->training_master->training_for_model_id;
            
            $docMaster = $this->DocMaster->find('all',["fields" => ["current_revision_no",'doc_no']])->where(['DocMaster.id' => $docMaster_id])->first();
            
            if($docMaster != null)
            {
                $docNum = !empty($docMaster)?$docMaster->doc_no:'-';
                $docRevNo = !empty($docMaster)?$docMaster->current_revision_no:'-';
            }
        }
        $doc_version_padding = CustomerCache::read("doc_rev_no_format_pad");
        $data = $trainingSlotData[0];
        $trainer = '';
        if (isset($data->training_batch) && $data->training_batch->instructor_type == 'Internal') {
            $trainer = !empty($data->training_batch->Instructor->userfullname)?$data->training_batch->Instructor->userfullname:"-";
        }
        else {
        $trainer = !empty($data->training_batch->Externalinstructor)?$data->training_batch->Externalinstructor->name:"-";
        }
        $trainingName = !empty($data->training_master)?$data->training_master->training_name:'';
        $venue = !empty($data->training_batch)?$data->training_batch->venue:'';
        $sloat_date = !empty($data)?$data->fslot_date:'';
       
        $Starttime = new FrozenTime($data->start_time);
        $sttime = $Starttime->format($timeViewFormat);        
        $strat_time = !empty($data->start_time)?$sttime:'';
        
        $Endtime = new FrozenTime($data->end_time);
        $endtime = $Endtime->format($timeViewFormat);
        $end_time = !empty($data->end_time)?$endtime:''; 
        
        $dateforcertificate  = CustomerCache::read("dateviewformat");
        $timeformatforcertificate = CustomerCache::read("timeformat");
        
        if($dateforcertificate==null){
            $dateforcertificate  = CustomerCache::read("dateviewformat",null,0);
        }
        if ($dateforcertificate == null){
            $dateforcertificate  = Configure::read("dateviewformat");
        }
        
        if($timeformatforcertificate==null){
            $timeformatforcertificate  = CustomerCache::read("timeformat",null,0);
        }
        if ($timeformatforcertificate == null){
            $timeformatforcertificate  = Configure::read("timeformat");
        }
        $this->loadModel('Tc.TrainingTypeSubtypeMaster');
        $dtformat=(CustomerCache::read("dateviewformat")!="")?CustomerCache::read("dateviewformat"):"d-m-Y";
        
        $currDate = date($dtformat);
        $currTime = date($timeformatforcertificate,null);
        //debug($currTime);
        $subtype=$this->TrainingTypeSubtypeMaster->find('all', ['field' => 'id','name','parent_id','type'])->where(["type"=>'Training','parent_id'=>1])->toArray();
        $training_type = !empty($data->training_master->training_type_subtype_master)?$data->training_master->training_type_subtype_master->name:'';//debug($data);exit;
        // $training_subtype = !empty($data->training_master->training_type_subtype_master->parent_id==1)?$data->training_master->training_type_subtype_master->name:'';
        $training_subtype ='-';
        foreach($data->training_master->training_type_subtype_master->child_training_type_subtype_master as $value){
            if($data->training_master->training_type_subtype_master_id == $value->id){
                // debug("hi");exit;
                $training_subtype = !empty($value)?$value->name:'-';
                
            }
        }
        
        
        $end_time = !empty($data->end_time)?$endtime:''; 
        //debug($data);die;
        // $training_subtype = !empty($data->training_master->training_type_subtype_master)?$data->training_master->training_type_subtype_master->name:'';
        
        $ownercompany =  $this->request->getSession()->read('customerDetails.company_name');
        $this->set(compact('loggedUserName','doc_version_padding','training_type','data','currDate','dateforcertificate','trainingSlotData','ownercompany','trainingName','venue','docNum','docRevNo','trainer','sloat_date','strat_time','end_time','training_subtype','currTime'));
        //Log::debug("attendance data :". print_r($data,true));
        $this->viewBuilder()->setLayout("blank");
   
        $headerHtml=$this->render("attendanceheaderpdf");
        if($type == "blank") {
            $html=$this->render("attendancepdf");
        } else {
            $html=$this->render("attendancepdf_aftersession");
        }
        
       
        $imgname = $this->getRequest()->getSession()->read('customerDetails.logo_image');
        $headerimage = "storage/customer/".$customer_id."/images/".$imgname."";

   
        
        $file_path=WWW_ROOT.'storage/customer/'.$customer_id. DS.'attendancesheet';
        if(!is_dir($file_path)){
            QMSFile::mkdir($file_path);
            //mkdir($file_path, 0755);
        }
        if(is_dir($file_path)){
          
            $CakePdf = new \Mpdf\Mpdf();
            
            $pdf_file=$file_path.DS.$slot_id.'_attendancesheet.pdf';
            $totalpagecnt="{nb}";
           
            //footer set 
                $footerNumber=CustomerCache::read("atendanceSheetPdfFooterNumber");
                $attendance_sign_date_show_flag = CustomerCache::read("attendance_sign_date_show_flag");
                $footerHTML = '';
                if($attendance_sign_date_show_flag == 'Y'){ 
                    $footerHTML =
                    "<tr>
                        <td style='text-align:right; border: none; padding-top:10px;padding-right:10px;' >
                        <p style='padding-bottom:30px;'> Sign/Date:</p>
                         <br>_____________________________________<br>
                            $trainer / $sloat_date
                        </td>
                    </tr>";
                } 

                $footerHTML = "<table style='font-size:16px; border: none;'>
                $footerHTML
                    <tr>
                        <td style='border: none; text-align:left;'>
                            Generated By :  $loggedUserName <br>
                            Generated On : $currDate  $currTime
                        </td>
                    </tr>
                     <tr>
                        <td  style='border: none; text-align: right;'> $footerNumber </td>
                    </tr>
                    <tr>
                        <td style='text-align:center; padding-top:30px; padding-bottom:30px; border: none;'>
                            <p style= 'text-align:center'>This is computer generated certificate hence signature is not required.</p>
                        </td>
                    </tr>
                 </table>";
            //----
            $CakePdf->tableMinSizePriority = false;
            $CakePdf->SetHTMLHeader($headerHtml);
            $CakePdf->SetHTMLfooter($footerHTML);
            $stylesheet = file_get_contents("css/pdf/gen_attendance.css");
            
            $CakePdf->WriteHTML($stylesheet,1);
            
            $CakePdf->AddPage('', // L - landscape, P - portrait
                '', '', '', '',
                5, // margin_left
                5, // margin right
                100, // margin top
                80, // margin bottom
                0, // margin header
                0); // margin footer
                if(file_exists($pdf_file)){
                    unlink($pdf_file);
                }
               
                $CakePdf->WriteHTML($html);
                //debug();die;
                $CakePdf->Output($pdf_file,'F');
                $pdfFile=new FileLib();
                $pdfFile->preview($pdf_file, "application/pdf" );
                return true;
        }else{
            $this->Flash->error(__('Sorry!!Problem in generating pdf.'));
            return $this->redirect(['plugin'=>false,'controller'=>'Pages','action' => 'home']);
        }
    }
    
    public function trainingCertificate($user_id=null,$training_compliance_id=null,$training_master_id=null,$istab = null)
    {
        $user_id = ($user_id == null) ? null : decryptVal($user_id);
        $training_compliance_id = ($training_compliance_id == null) ? null : decryptVal($training_compliance_id);
        $training_master_id = ($training_master_id == null) ? null : decryptVal($training_master_id);
        $session = $this->request->getSession()->read('Auth');
        $plugin=$this->request->getParam('plugin');
        $method=$this->request->getParam('action');
        $controller=$this->request->getParam('controller');
        
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        $customer_id=$session['customer_id'];
        $location_id=$session['base_location_id'];
        
        $this->loadModel('Tc.TrainingCompliance');
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('Users');
        
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        if (!$this->Authorization->can($trainingCompliance, 'viewCertificate')) {
            $this->Flash->error(__('You are not authorized to access this page!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        $trainingMaster = $this->TrainingMaster->find('all',[
            'fields' => ['training_name','passing_score','out_of_marks']])->where(['id'=>$training_master_id,])->toArray();
        
        $users = $this->Users->find('all',[
            'fields' => ['userfullname','emp_id']])->where(['id'=>$user_id,])->first();
        
        $dateforcertificate  = CustomerCache::read("dateinputformat");
        if($dateforcertificate==null){
            $dateforcertificate  = CustomerCache::read("dateinputformat",null,0);
        }
        if ($dateforcertificate == null){
            $dateforcertificate  = Configure::read("dateinputformat");
        }
        
        $timeformatforcertificate = CustomerCache::read("timeformat");
        if($timeformatforcertificate==null){
            $timeformatforcertificate  = CustomerCache::read("timeformat",null,0);
        }
        if ($timeformatforcertificate == null){
            $timeformatforcertificate  = Configure::read("timeformat");
        }
        
        $trainingCompliance = $this->TrainingCompliance->find('all',[
            'contain' => ['TrainingMaster','Users','TrainingBatch'=>['TrainingSessionSlots'=>['TrainingSlotAttendance'],'Instructor',]],'disabledBeforeFind'=>true])->where(['TrainingCompliance.training_id'=>$training_master_id,'TrainingCompliance.user_id'=>$user_id])->first();
        
       
        
        $SourceNumber=[];
        if($trainingCompliance->training_master->document_number==null){
            if($trainingCompliance->training_master->training_for_model_name!='Others' || $trainingCompliance->training_master->document_number!=''){
                if($trainingCompliance->training_master->training_for_model_name == 'Ca' || $trainingCompliance->training_master->training_for_model_name=='Pa'){
                    $pluginName='Capa';
                }else{
                    $pluginName = $trainingCompliance->training_master->training_for_model_name;
                }
                $modelReferenceName=$this->loadModel($pluginName);
                if(isset($trainingCompliance->training_master->training_for_model_id) && !empty($trainingCompliance->training_master->training_for_model_id)){
                    if($pluginName=='DocMaster'){
                        $SourceNumber = $modelReferenceName->get($trainingCompliance->training_master->training_for_model_id,[
                            'fields'=>['id','title','doc_no','current_revision_no']
                        ]);
                    }else{
                    $SourceNumber = $modelReferenceName->get($trainingCompliance->training_master->training_for_model_id,[
                        'fields'=>['id','reference_number','title']
                    ]);
                    }
                }
            }
        }
        if(empty($SourceNumber)){
            $trainingCompliance->modelReferenceNo = $trainingCompliance->training_master->document_number;
        }else{
            $pluginName = $trainingCompliance->training_master->training_for_model_name;
            if($pluginName=='DocMaster'){
                $trainingCompliance->modelReferenceNo = $SourceNumber->doc_no;
                $trainingCompliance->docVersionNo = $SourceNumber->current_revision_no;
            }else{
                $trainingCompliance->modelReferenceNo = $SourceNumber->reference_number;
            }
        }
        $doc_version_padding = CustomerCache::read("doc_rev_no_format_pad");
        
        $dtformat=(CustomerCache::read("dateviewformat")!="")?CustomerCache::read("dateviewformat"):"d-m-Y";
        
        $currDate = date($dtformat);
        $currTime = date($timeformatforcertificate,null);
        //debug($currTime);
        $userName=$this->request->getSession()->read('Auth.userfullname');
        $this->set(compact('istab','userName','user_id','trainingCompliance','trainingMaster','users','dateforcertificate','doc_version_padding','currDate','currTime'));
        
        
    }
    
    //training certificate pdf genrate
    public function trainingcertificatedata($user_id=null,$training_compliance_id=null,$training_master_id=null)
    {
        $user_id = ($user_id == null) ? null : decryptVal($user_id);
        $training_compliance_id = ($training_compliance_id == null) ? null : decryptVal($training_compliance_id);
        $training_master_id = ($training_master_id == null) ? null : decryptVal($training_master_id);
        
        $this->loadModel('Users');
        $users = $this->Users->find('all',[
            'fields' => ['userfullname','emp_id']])->where(['id'=>$user_id,])->first();
        
        $this->loadModel('Tc.TrainingCompliance');
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        if (!$this->Authorization->can($trainingCompliance, 'viewCertificate')) {
            $this->Flash->error(__('You are not authorized to access this page!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $this->loadModel('Tc.TrainingMaster');
        $trainingMaster = $this->TrainingMaster->find('all',[
            'fields' => ['training_name','passing_score','out_of_marks']])->where(['id'=>$training_master_id,])->toArray();
        
        $dateforcertificate  = CustomerCache::read("dateinputformat");
        if($dateforcertificate==null){
            $dateforcertificate  = CustomerCache::read("dateinputformat",null,0);
        }
        if ($dateforcertificate == null){
            $dateforcertificate  = Configure::read("dateinputformat");
        }
        $trainingCompliance = $this->TrainingCompliance->find('all',[
            'contain' => ['TrainingMaster','Users','TrainingBatch'=>['TrainingSessionSlots'=>['TrainingSlotAttendance'],'Instructor',]]])->where(['TrainingCompliance.training_id'=>$training_master_id,'TrainingCompliance.user_id'=>$user_id])->first();
        
        $SourceNumber=[];
        if($trainingCompliance->training_master->document_number==null){
            if($trainingCompliance->training_master->training_for_model_name!='Others' || $trainingCompliance->training_master->document_number!=''){
                if($trainingCompliance->training_master->training_for_model_name == 'Ca' || $trainingCompliance->training_master->training_for_model_name=='Pa'){
                    $pluginName='Capa';
                }else{
                    $pluginName = $trainingCompliance->training_master->training_for_model_name;
                }
                $modelReferenceName=$this->loadModel($pluginName);
                if(isset($trainingCompliance->training_master->training_for_model_id) && !empty($trainingCompliance->training_master->training_for_model_id)){
                    if($pluginName=='DocMaster'){
                        $SourceNumber = $modelReferenceName->get($trainingCompliance->training_master->training_for_model_id,[
                            'fields'=>['id','title','doc_no','current_revision_no']
                        ]);
                    }else{
                        $SourceNumber = $modelReferenceName->get($trainingCompliance->training_master->training_for_model_id,[
                            'fields'=>['id','reference_number','title']
                        ]);
                    }
                }
            }
        }
        if(empty($SourceNumber)){
            $trainingCompliance->modelReferenceNo = $trainingCompliance->training_master->document_number;
        }else{
            $pluginName = $trainingCompliance->training_master->training_for_model_name;
            if($pluginName=='DocMaster'){
                $trainingCompliance->modelReferenceNo = $SourceNumber->doc_no;
                $trainingCompliance->docVersionNo = $SourceNumber->current_revision_no;
            }else{
                $trainingCompliance->modelReferenceNo = $SourceNumber->reference_number;
            }
        }
        $doc_version_padding = CustomerCache::read("doc_rev_no_format_pad");
        
        $dtformat=(CustomerCache::read("dateviewformat")!="")?CustomerCache::read("dateviewformat"):"d-m-Y";
        
        $currDate = date($dtformat);
        
        $timeformatforcertificate = CustomerCache::read("timeformat");
        if($timeformatforcertificate==null){
            $timeformatforcertificate  = CustomerCache::read("timeformat",null,0);
        }
        if ($timeformatforcertificate == null){
            $timeformatforcertificate  = Configure::read("timeformat");
        }
        $currTime = date($timeformatforcertificate,null);
        
        $userName=$this->request->getSession()->read('Auth.userfullname');
        $this->viewBuilder()->setLayout("blank");
        $this->set(compact('trainingMaster','users','dateforcertificate','trainingCompliance','doc_version_padding','currDate','userName','currTime'));
        $html = $this->render("certificate_pdf");
        
        $CakePdf = new \Mpdf\Mpdf([
            'compress' => true, // Enable compression
        ]);
        
        $pdf_file='cert.pdf';
        
        $CakePdf->tableMinSizePriority = false;
        $CakePdf->SetHTMLHeader('');
        $CakePdf->SetHTMLfooter('');
        $CakePdf->AddPage('', // L - landscape, P - portrait
            '', '', '', '',
            5, // margin_left
            5, // margin right
            35, // margin top
            30, // margin bottom
            0, // margin header
            0); // margin footer
            
            $CakePdf->WriteHTML($html);
            $CakePdf->Output($pdf_file,'F');
            $pdfFile=new FileLib();
            $pdfFile->preview($pdf_file, "application/pdf" );
            return true;
    }
    
    public function monthlyview(){
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingIndex = $trainingCompliance;
        if (!$this->Authorization->can($trainingCompliance, 'MonthlyView')) {
            $this->Flash->error(__('You are not authorized to access this page!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        $fdate = '';
        $tdate = '';
        if ($this->request->is(['get'])) {
            $fdate = $this->request->getQuery('fdate');
            $tdate = $this->request->getQuery('tdate');
            $gtype = $this->request->getQuery('type');
            //debug($fdate);exit;
        }
        if($fdate == null)
        {
            $fdate = date('Y-m-d', time());
            $time = strtotime($fdate);
            $displaymonth=date("F",$time);
            $displayYear=date("Y",$time);
            
        }
        if($tdate == null)
        {
            $tdate = date('Y-m-d', time());
        }
        if($gtype == null)
        {
            $gtype = '1';
        }
        
        $this->loadModel('Tc.TrainingBatch');
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('Tc.TrainingCompliance');
//         $trainingBatch = $this->TrainingBatch->find('all', ["contain" => ["TrainingMaster"=>['fields'=>['id','training_name','reference_number','type']],
//             'Instructor'=>['fields'=>['id','userfullname']],
//             'TrainingCompliance'=>['Assigned'],
//         ],
//             'conditions'=>['TrainingBatch.to_date <=' => $tdate,
//                 'TrainingBatch.from_date >=' => $fdate,]
//         ]);
        
       
        $trainingBatch = $this->Authorization->applyScope($this->TrainingMaster->find('all', [
            "contain" => [
                'TrainingBatch' => [
                    'TrainingCompliance' => ['Assigned'],
                    'Instructor' => ['fields' => ['id', 'userfullname']],
                ],
            ],
            'order' => ['TrainingMaster.id DESC'],
            'conditions' => [
                'OR' => [
                    'TrainingMaster.due_date <=' => $tdate,
                    'TrainingMaster.due_date >' => $tdate, // Use '>' instead of '>=' here
                ],
            ],
        ]),'MonthlyView');
        
        
//               $trainingBatch = $this->TrainingCompliance->find('all', [
//                     "contain" => [
//                        "TrainingMaster"=>['fields'=>['id','training_name','reference_number','type']],
//                         'Assigned','TrainingBatch'=>[
//                                 'Instructor'=>['fields'=>['id','userfullname']],
//                                 'conditions'=>[
//                                         'TrainingBatch.to_date <=' => $tdate,
//                                         'TrainingBatch.from_date >=' => $fdate
            
//                                     ],
            
//                                 ],
//                     ],
//                             'order'=>['TrainingMaster.id DESC'],
//                             'conditions'=>[
//                                 'TrainingCompliance.created <=' => $tdate,
//                                 'TrainingCompliance.created >=' => $fdate
                                
//                             ],
//                           //   'group'=>'TrainingCompliance.training_batch_id'
//                         ]);
        
        $this->loadModel('Tc.TrainingTypeSubtypeMaster');
        $type = $this->TrainingTypeSubtypeMaster->find('list', ['keyField' => 'id','valueField' => 'name'])->where(["type"=>'Training','parent_id'=>0])->toArray();
        
        $trainingbatchexport =  $trainingBatch->toArray();
      //debug($trainingbatchexport);die;
        $trainingBatch = $this->paginate($trainingBatch);
       // debug($trainingBatch);die;
        $this->set(compact('trainingBatch','trainingIndex','fdate','tdate','trainingbatchexport','type','gtype'));
        
        if ($this->request->getQuery('export') != null) {
                $conn = $this;
                $CallBackStream = new CallbackStream(function () use ($conn,$trainingbatchexport) {
                    try {
                        $conn->viewBuilder()->setLayout("xls");
                        $conn->set(compact('trainingbatchexport'));
                        echo $conn->render('monthlyviewexcel');
                    } catch (Exception $e) {
                        echo $e->getMessage();
                        $e->getTrace();
                    }
                });
                    return $this->response->withBody($CallBackStream)
                    ->withAddedHeader("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
                    ->withAddedHeader("Content-disposition", "attachment; filename=Trainining Plan.xls");
                    
        }
        
        if ($this->request->getQuery('pdf') != null) {
            $this->viewBuilder()->setLayout("blank");
            $html = $this->render("monthlyviewexcel");
         //   $html =$this->render('monthlyviewexcel');
          // debug($html);die;
            $CakePdf = new \Mpdf\Mpdf([
                'compress' => true, // Enable compression
            ]);
            
            $pdf_file='Month.pdf';
            
            $CakePdf->tableMinSizePriority = false;
//             $CakePdf->SetHTMLHeader('');
//             $CakePdf->SetHTMLfooter('');
            $CakePdf->AddPage('', // L - landscape, P - portrait
                '', '', '', '',
                5, // margin_left
                5, // margin right
                35, // margin top
                30, // margin bottom
                0, // margin header
                0); // margin footer
                
                $CakePdf->WriteHTML($html);
                $CakePdf->Output($pdf_file,'D');
//                 $pdfFile=new FileLib();
//                 $pdfFile->preview($pdf_file, "application/pdf" );
                return true;
                
        }
    }
    
    public function deactivetrainingbyrole()
    {
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        $customer_id=$session['customer_id'];
        $location_id= $session['base_location_id'];
        $group_id=$this->request->getSession()->read('Auth.groups_id');
        if (!$this->Authorization->can($trainingCompliance, 'ByRole')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $this->loadModel('FunctionalRoles');
        $search_customer_id=$this->request->getQuery('search_customer_id');
        $role_name=$this->request->getQuery('role_name');
        $data=$this->request->getData(); //debug($data);die;
        $conditions=[];
        if($role_name != ""){ $conditions["AND"][]=['OR'=>["role_name Like '%$role_name%' "]]; }
        $functionalRole = $this->FunctionalRoles->newEmptyEntity();
        $functionalRole = $this->FunctionalRoles->patchEntity($functionalRole, $data);
        $functionalRoleResult=$this->FunctionalRoles->save($functionalRole);
        
        $this->paginate = [
            'contain' => ['Users'],
            'conditions'=>['FunctionalRoles.status'=>0,$conditions],
            'order'=>['deactivate'=>'desc']
        ];
        $query=$this->request->getQuery("table_search");
        if($query!=null && trim($query," ")!=""){
            $conditions["or"][]="FunctionalRoles.role_name like '%$query%'";
            $conditions["or"][]="Customer.company_name like '%$query%'";
        }
        if($session['groups_id'] != 1){
            $conditions=array_merge($conditions,['FunctionalRoles.customer_id'=>$session['customer_id']]);
        }
        $functionalRoles = $this->paginate($this->FunctionalRoles);
        $this->loadModel("Customer");
        $customer= $this->Customer->find("list", ['keyField' => 'id','valueField' => 'company_name'])->toArray();
        $this->getTransPass();
        $this->set(compact('functionalRoles','group_id','customer','search_customer_id','customer_id','location_id'));
    }
    
    ######## Section Deactive to Active ###########
    
    public function activeTrainer()
    {
        $session = $this->request->getSession()->read("Auth");
        $loggedUserId = $session['id'];
        $this->loadModel('FunctionalRoles');
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        if (!$this->Authorization->can($trainingCompliance, 'add')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        if ($this->request->is(['patch', 'post', 'put'])) {
            $saveData = $this->request->getData();
            $id = $saveData['id'];
            $functionalRole = $this->FunctionalRoles->get($id);
            $saveData['activate_by'] = $loggedUserId;
            $saveData['status'] = 1;
            $update_sectionmaster = $this->FunctionalRoles->patchEntity($functionalRole, $saveData);
            $update_master = $this->FunctionalRoles->save($update_sectionmaster);
            
            if ($update_master) {
                $this->Flash->success(__('The Training Role has been activated.', 'TrainingCompliance'));
            } else {
                $this->Flash->error(__('Failed to activate the Training Role.', 'TrainingCompliance'));
            }
        }
        return $this->redirect(['action' => 'trainingByRole']);
    }
    
    ######## Section Active to Deactive ###########
    
    public function deactiveTrainer()
    {
        $session = $this->request->getSession()->read("Auth");
        $loggedUserId = $session['id'];
        $role_name=$this->request->getQuery('role_name');
        $this->loadModel('FunctionalRoles');
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        
        if (!$this->Authorization->can($trainingCompliance, 'add')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        if ($this->request->is(['patch', 'post', 'put'])) {
            $saveData = $this->request->getData();
            $id = $saveData['id'];
            $functionalRole = $this->FunctionalRoles->get($id);
            $saveData['deactivate_by'] = $loggedUserId;
            $saveData['activate_reason'] = "";
            $saveData['activate_by'] = "";
            $saveData['status'] = 0;
            //             $saveData['modifyed'] = new FrozenTime(date($this->DateTimeFormat));
            $saveData['deactivate'] = new FrozenTime(date($this->DateTimeFormat));
            $update_sectionmaster = $this->FunctionalRoles->patchEntity($functionalRole, $saveData);
            $update_master = $this->FunctionalRoles->save($update_sectionmaster);
            if ($update_master) {
                $this->Flash->success(__('The Training Role has been deactivated.', 'TrainingCompliance'));
            } else {
                $this->Flash->error(__('Failed to deactivate the Training Role.', 'TrainingCompliance'));
            }
            
            return $this->redirect(['action' => 'deactivetrainingbyrole']);
        }
    }
    
    ################# training assign by Department ################
    public function trainingByDepartment()
    {
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        
        if (!$this->Authorization->can($trainingCompliance, 'ByDepartment')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        $session = $this->request->getSession()->read('Auth');
        $customer_id=$session['customer_id'];
        $location_id= $session['base_location_id'];
        $group_id=$this->request->getSession()->read('Auth.groups_id');
        $this->loadModel('Departments');
        $search_customer_id=$this->request->getQuery('search_customer_id');
        $userIdentity = $this->request->getAttribute('identity');
        $isForTrainingHead = false;
        if ($userIdentity->can('TrainingHead', $trainingCompliance)) {
            $isForTrainingHead = true;
        }
        $data['active'] = 1;
        if ($this->request->is('post')) {
            $data=$this->request->getData(); //debug($data);die;
            $department = $this->Departments->newEmptyEntity();
            $department = $this->Departments->patchEntity($department, $data);
            $departmentResult=$this->Departments->save($department);
            if ($departmentResult) {
                $this->Flash->success(__('The Department has been saved.'));
                return $this->redirect(['action' => 'trainingByDepartment']);
            }else{
                $this->Flash->error(__('The Department could not be saved.'));
            }
        }
        $conditions=array("customer_id"=>$customer_id);
        $this->paginate = [
            'contain'=>['customer',	'customer_locations'],
            'conditions'=>$conditions,
            "order"=>['id'=>'desc'],
        ];
        if($search_customer_id != ""){ $cond=["Departments.customer_id"=>$search_customer_id]; }else{$cond='';}
        $this->paginate = [
            'contain'=>['Customer'],
            'conditions'=>['Departments.active'=>1,$cond],
            "order"=>['id'=>'desc'],
        ];
        if ($isForTrainingHead) {
            $query = $this->Departments->find();
        }
        else {
            $query = $this->Authorization->applyScope($this->Departments->find(),'ByDepartment');
        }
        $department = $this->paginate($query);
        $isTransPassword = CustomerCache::read("transactional_password_required");
        if ($isTransPassword == 'Y') {
            $transPass = 1;
        }
        else {
            $transPass = 0;
        }
        $this->loadModel('TrainingMaster');
        $this->loadModel('Users');
        
        $departmentTrainingCounts = [];
        $usersCounts = [];
        
        foreach ($department as $dept) {
            
            $assignedTrainings = $this->TrainingMaster->find('list', [
                'keyValue' => 'id', 'valueField' => 'training_name',
                'conditions' => [
                    "JSON_CONTAINS(TrainingMaster.selected_department, '\"$dept->id\"')",
                ],
                'select' => ['count' => 'COUNT(*)']
            ]);
            
            $userCount = $this->Users->find()
            ->where(['departments_id' => $dept->id,
                'customer_id' => $customer_id, 
                'base_location_id' => $location_id, 
            ]) 
            ->count();
            
            $usersCounts[$dept->id] = $userCount;
            $departmentTrainingCounts[$dept->id] = $assignedTrainings->all()->count();
        }
        
        $this->loadModel("Customer");
        $customer= $this->Customer->find("list", ['keyField' => 'id','valueField' => 'company_name'])->toArray();
        $this->set(compact('transPass','department','group_id','customer','search_customer_id','customer_id','location_id','departmentTrainingCounts','usersCounts',));
    }
    
    ################# training assign by Department ################
    public function assignTrainingByDepartment($department_id=null)
    {
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $this->loadModel('Departments');
        $department_id=($department_id==null)?null:decryptVal($department_id);
        $department = $this->Departments->get($department_id, [
            'contain' => [],
        ]);
        $this->loadModel('Tc.TrainingMaster');
        $search_customer_id=$this->request->getQuery('search_customer_id');
        
        if (!$this->Authorization->can($trainingCompliance, 'ByDepartment')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        $customer_id=$this->request->getSession()->read('Auth.customer_id');
        $location_id=$this->request->getSession()->read('Auth.base_location_id');
        $group_id=$this->request->getSession()->read('Auth.groups_id');
        
        if ($this->request->is(['patch', 'post', 'put'])) {
            $saveData = $this->request->getData();
            $this->loadModel('TrainingMaster');
            $newSelectedDepartment = (int)$saveData['selected_department'];
            $trainingMasterIds = $saveData['training_master'];
            $allTrainingsID = $this->TrainingMaster->find('list', [
                'keyValue' => 'id', 'valueField' => 'id',
                'conditions' => [
                    "JSON_CONTAINS(TrainingMaster.selected_department, '\"$department_id\"')",
                ],
            ])->toArray();
            $result = array_diff($allTrainingsID,$saveData['training_master']);
            if (empty($result)) {
                $jsonArrayAppend = "JSON_ARRAY_APPEND(selected_department, '$', CAST(:newSelectedDepartment AS CHAR))";
                $sql = "UPDATE training_master SET selected_department = $jsonArrayAppend WHERE id IN (" . implode(',', $trainingMasterIds) . ")
                     AND NOT JSON_CONTAINS(selected_department, JSON_QUOTE(:newSelectedDepartment))
                     ";
                $params = [
                    'newSelectedDepartment' => $newSelectedDepartment,
                ];
                $this->TrainingMaster->getConnection()->execute($sql, $params);
            }else{
                $idsToRemove = [];
                foreach ($result as $idToRemove) {
                    $idsToRemove[] = $idToRemove;
                }
                $sql = "UPDATE training_master SET selected_department = JSON_REMOVE(selected_department, JSON_UNQUOTE(JSON_SEARCH(selected_department, 'one', :newSelectedDepartment)))
                     WHERE id IN (" . implode(',', $idsToRemove) . ") AND JSON_CONTAINS(selected_department, JSON_QUOTE(:newSelectedDepartment))
                     ";
                $params = [
                    'newSelectedDepartment' => $newSelectedDepartment,
                ];
                $this->TrainingMaster->getConnection()->execute($sql, $params);
            }
        }
        
        $assignedTrainings = $this->TrainingMaster->find('list', [
            'keyValue' => 'id', 'valueField' => 'training_name',
            'conditions' => [
                "JSON_CONTAINS(TrainingMaster.selected_department, '\"$department_id\"')",
            ],
        ]);
        $allTrainings = $this->TrainingMaster->find('list', [
            'keyValue' => 'id', 'valueField' => 'training_name',
            'conditions' => [
                "NOT JSON_CONTAINS(TrainingMaster.selected_department, '\"$department_id\"')",
            ],
        ]);
        $isTransPassword = CustomerCache::read("transactional_password_required");
        if ($isTransPassword == 'Y') {
            $transPass = 1;
        }
        else {
            $transPass = 0;
        }
        $this->set(compact('department','allTrainings','assignedTrainings','transPass'));
    }
    //added by shrirang
    public function complianceview($training_id=null){
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingIndex = $trainingCompliance;
        if (!$this->Authorization->can($trainingCompliance, 'ComplianceView')) {
            $this->Flash->error(__('You are not authorized to access this page!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $training_id = ($training_id == null) ? null : decryptVal($training_id);
       
        $this->loadModel('Tc.TrainingBatch');
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('Tc.TrainingCompliance');
        $this->loadModel('Tc.TrainingSessionSlots');
        $statusoption = $this->request->getQuery("status");
     //  debug($statusoption);
        $statuscondtion = [];
        if($statusoption != null || $statusoption !=''){
            $statuscondtion = ['TrainingCompliance.status' => $statusoption];
        }
     
        $trainingBatch = $this->TrainingMaster->find('all', [
            "contain" => [
                'TrainingCompliance' => [
                    'conditions' => $statuscondtion,
                    'Assigned','TrainingSlotAttendance'=>['TrainingBatch',
                    'Users' => [
                        'fields' => ["userfullname", "emp_id"],
                    ]],
                    'TrainingBatch',
                ],
            ],
            'order' => ['TrainingMaster.id DESC'],
            'conditions' => ['TrainingMaster.id' => $training_id],
        ])->toArray();
        
        foreach ($trainingBatch as &$trainingBatchval) {
            usort($trainingBatchval->training_compliance, function ($a, $b) {
                if (isset ($a->Assigned->userfullname) && isset ($b->Assigned->userfullname))  {
                return strcmp($a->Assigned->userfullname, $b->Assigned->userfullname);
                }
            });
        }
       // debug($trainingBatch);die;
       $session = $this->request->getSession()->read('Auth');
       $customer_id=$session['customer_id'];
       $location_id=$this->request->getSession()->read('Auth.base_location_id');
       $this->loadModel("Departments");
       $departments = $this->Departments->find('list', [
        'conditions' => ['customer_id' => $customer_id], 'keyValue' => 'id', 'valueField' => 'department'])->toArray();
       $this->loadModel("Users");
       $users = $this->Users->find('list', [
           'conditions' => ['customer_id' => $customer_id,'base_location_id'=>$location_id], 'keyValue' => 'id', 'valueField' => 'userfullname'])->toArray();
       

        $this->set(compact('trainingBatch', "departments","users"));
        
        if ($this->request->getData('export') != null) {
            
            $conn = $this;
            $CallBackStream = new CallbackStream(function () use ($conn,$trainingBatch) {
                try {
                    $conn->viewBuilder()->setLayout("xls");
                    $conn->set(compact('trainingBatch'));
                    echo $conn->render('complianceview_export');
                } catch (Exception $e) {
                    echo $e->getMessage();
                    $e->getTrace();
                }
            });
                return $this->response->withBody($CallBackStream)
                ->withAddedHeader("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
                ->withAddedHeader("Content-disposition", "attachment; filename=Trainingwise users.xls");
                
        }

        if ($this->request->getData('exportpdf') != null) {
            $pdfCon = new PdfController();
            $this->viewBuilder()->setLayout("blank");
            $this->set(compact('trainingBatch'));
            $html = $this->render('complianceview_export');
            $pdfCon->exportToPDF($html, 'Training Details - By Training');           
                
        }
        
    }
    
    
    public function myLastEvaluationpdf($user_id=null,$training_compliance_id=null,$training_master_id=null,$attempt=null){
        $user_id = ($user_id == null) ? null : decryptVal($user_id);
        $training_compliance_id = ($training_compliance_id == null) ? null : decryptVal($training_compliance_id);
        $training_master_id = ($training_master_id == null) ? null : decryptVal($training_master_id);
        $attempt = ($attempt == null) ? null : decryptVal($attempt);
        $session=$this->request->getSession()->read("Auth");
        $customer_id=$session['customer_id'];
        $location_id=$session['base_location_id'];
        $this->loadModel('Tc.TrainingCompliance');
        $this->loadModel('Tc.TrainingMaster');
        $this->loadModel('Tc.SectionMaster');
        $this->loadModel('Tc.QuestionBankMaster');
        $this->loadModel('Tc.QuestionBankMcqOptions');
        $this->loadModel('Tc.TrainingTestResult');
        
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $session = $this->request->getSession()->read('Auth');
        if (!$this->Authorization->can($trainingCompliance, 'MyLastEvaluationpdf')) {
            $this->Flash->error(__('You are not authorized to access this page!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        $trainingCompliance = $this->TrainingCompliance->get($training_compliance_id,['contain'=>['TrainingMaster']]);
       
        if($attempt == true){
        $questionMaster=$this->TrainingTestResult->find('all', [
            'contain'=>['Users','TrainingMaster','QuestionBankMaster','TrainingCompliance'],//
        ])->where(['TrainingTestResult.training_master_id'=>$training_master_id,'TrainingTestResult.training_complaince_id'=>$training_compliance_id,'TrainingTestResult.attempt'=>$attempt])->toArray();
       
       
        $questionIds=[];
        $questionMasterMcqData='';
        foreach ($questionMaster as $key=>$question)
        {
            $questionIds[$key]=$question->question_bank_master_id;
        }
        if(!empty($questionIds)){
            $questionMasterMcqData=$this->QuestionBankMaster->find('all',['contain'=>['QuestionBankMcqOptions']])->where(['id IN'=>$questionIds])->toArray();
        }
        $this->set(compact('questionMaster','training_compliance_id','training_master_id','user_id','attempt','questionMasterMcqData','trainingCompliance'));
        
        
        $this->viewBuilder()->setLayout("blank");
        $html = $this->render("my_last_evaluationpdf");
        $headerHtml=$this->render("attendanceheaderpdf");
        
        $CakePdf = new \Mpdf\Mpdf([
            'compress' => true, // Enable compression
        ]);
        $CakePdf->autoScriptToLang = true;
        $CakePdf->autoLangToFont = true;
        $pdf_file='questionpaper.pdf';
        
        $CakePdf->tableMinSizePriority = false;
//         $CakePdf->SetHTMLHeader('');
//         $CakePdf->SetHTMLfooter('');
        $CakePdf->AddPage('', // L - landscape, P - portrait
            '', '', '', '',
            5, // margin_left
            5, // margin right
            35, // margin top
            30, // margin bottom
            0, // margin header
            0); // margin footer
            
            $CakePdf->WriteHTML($html);
            $CakePdf->Output($pdf_file,'F');
            $pdfFile=new FileLib();
            $pdfFile->preview($pdf_file, "application/pdf" );
            return true;
        }
    }
    
    
    public function showDocTraining($doc_id=null)
    {
        $doc_id = ($doc_id == null) ? null : decryptVal($doc_id);
        //debug($doc_id);die;
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingIndex = $trainingCompliance;
        $session = $this->request->getSession()->read('Auth');
        $base_location_id = $session['base_location_id'];
        $customer_id = $session['customer_id'];
        if (!$this->Authorization->can($trainingCompliance, 'usertrainingindex')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        $userIdentity = $this->request->getAttribute('identity');
        $isForTrainingHead = false;
        if ($userIdentity->can('TrainingHead', $trainingIndex)) {
            $isForTrainingHead = true;
        }
        
        $master= $this->loadModel('Tc.TrainingMaster');
       
        if ($isForTrainingHead) {
             $userCondition = [];
        } else {
            $userCondition = [];
        }
        $Condition = ['TrainingCompliance.status !='=>4];
        $master = $this->TrainingMaster->find('all', [
            'contain' => [
                'TrainingCompliance' => [
                    'conditions' => $Condition,
                    'Users' => [
                        'Departments',
                        'conditions' => $userCondition 
                    ]
                ]
            ],
            'conditions' => ['TrainingMaster.training_for_model_id' => $doc_id]
        ]);
            
            $master = $this->paginate($master);
           
            $this->loadModel('DocMaster');
            $docmaster = $this->DocMaster->find('all')->where(["DocMaster.id" => $doc_id])->toArray();
            
            $allmaster = $this->TrainingMaster->find('all', [
                'contain' => [
                    'TrainingCompliance' => [
                        'Users' => [
                            'Departments',
                            'conditions' => $userCondition
                        ]
                    ]
                ],
                'conditions'=>['TrainingMaster.training_for_model_id' => $doc_id],
            ])->toArray();

            $this->set(compact('master','trainingIndex','docmaster','allmaster'));
            
            //code for Export added by Vaibhavi
            if ($this->request->getQuery('export') != null) {
                $conn = $this;
                $CallBackStream = new CallbackStream(function () use ($conn,$master) {
                    try {
                        $conn->viewBuilder()->setLayout("xls");
                        $conn->set(compact('master'));
                        echo $conn->render('show_doc_training_export');
                    } catch (Exception $e) {
                        echo $e->getMessage();
                        $e->getTrace();
                    }
                });
                    return $this->response->withBody($CallBackStream)
                    ->withAddedHeader("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
                    ->withAddedHeader("Content-disposition", "attachment; filename=Documentwise Trainings.xls");
                    
            }
            //end here

            //code for Export added by Ganesh
            if ($this->request->getQuery('exportpdf') != null) {
                $pdfCon = new PdfController();
                $this->viewBuilder()->setLayout("blank");
                $this->set(compact('master'));
                $html = $this->render('show_doc_training_export');
                $pdfCon->exportToPDF($html, 'Training Details - By Document');                                    
            }
            //end here
            
    }
    
    public function myfailedTrainings()
    {
        $this->loadModel('DocMaster');
        $trainingCompliance = $this->TrainingCompliance->newEmptyEntity();
        $trainingIndex = $trainingCompliance;
        $session = $this->request->getSession()->read('Auth');
        $base_location_id = $session['base_location_id'];
        $customer_id = $session['customer_id'];
        if (!$this->Authorization->can($trainingCompliance, 'usertrainingindex')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        if (!$this->Authorization->can($trainingCompliance, 'Myfailedtrainings')) {
            $this->Flash->error(__('You are not authorized user to access!!!'));
            return $this->redirect(['plugin' => false, 'controller' => 'Pages', 'action' => 'home']);
        }
        
        $user_id = $session->id;
        
        
        $condition = [
            'user_id'=>$user_id,'TrainingCompliance.status'=>4,'TrainingMaster.id IS NOT NULL','TrainingMaster.type'=>2,"YEAR(TrainingCompliance.due_date) >= YEAR(CURDATE())"
        ];
        $query=$this->request->getQuery("section_name");
        if($query!=null && trim($query," ")!=""){
            $condition["or"][]="TrainingMaster.training_name like '%$query%'";
        }
        $this->paginate = [
            'contain' => ['TrainingMaster','TrainingBatch', "TrainingTestResult"],
            'conditions'=>$condition,
            'order'=>['id'=>'Desc'],
            'disabledBeforeFind'=>true
        ];
        
        $query = $this->Authorization->applyScope($this->TrainingCompliance->find(),'FailedTrainings');
        
        $trainingCompliance = $this->paginate($query);
       
        foreach ($trainingCompliance as $key=>$tc):
        $docmasterName = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_name:'';
        $docmasterId = isset($tc->training_master->training_for_model_id)?$tc->training_master->training_for_model_id:'';
        
        if (isset($docmasterId) && $docmasterName == 'DocMaster') {
            $docMaster_id = $tc->training_master->training_for_model_id;
            $docMaster = $this->DocMaster->find('all',["fields" => ["current_revision_no",'doc_no']])->where(['DocMaster.id' => $docMaster_id])->first()->toArray();
            $docData = array('docmaster_id'=>$docMaster_id, 'current_revision_no'=> $docMaster['current_revision_no'],'doc_no'=>$docMaster['doc_no']);
            $tc->training_master->docmaster = $docData;
        }
        else {
            continue;
        }
        endforeach;
        $this->getUserTrainingData($user_id);
        $this->loadModel('Tc.TrainingMaster');
        
        $this->loadModel('TrainingTypeSubtypeMaster');
        $type = $this->TrainingTypeSubtypeMaster->find('list', ['keyField' => 'id','valueField' => 'name'])->where(["type"=>'Training'])->toArray();
        
        $this->set(compact('user_id','trainingCompliance','trainingIndex','type'));
        $this->set('dateTimeFormat',$this->DateTimeFormat);
    }
    
   
 //end here
}
    
