<?php
declare(strict_types=1);

namespace Dev\Controller;

use App\Utility\CustomerCache;
use App\Utility\QMSFile;
use Cake\Core\Configure;
use App\Notification\Simple\SimpleNotification;
use App\Lib\FileLib;
use App\Model\Entity\DevCustomerApproval;
use Cake\Http\CallbackStream;
use AuditStash\Exception;
use Cake\Http\Client;
use Cake\Routing\Router;

/**
 * Deviation Controller
 *
 * @property \Dev\Model\Table\DeviationTable $Deviation
 *
 * @method \Dev\Model\Entity\Deviation[]|\Cake\Datasource\ResultSetInterface paginate($object = null, array $settings = [])
 */
class DeviationController extends AppController
{
    /**investigation_approval_required
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
        
    }
    public function index($isPlanned=null)
    {   
        $deviationsEntity=$this->Deviation->newEmptyEntity();
        if(!$this->Authorization->can($deviationsEntity, 'index')){
            $this->Flash->error(__('You dont have permission to create deviation.'));
            return $this->redirect(['plugin'=>false,'controller'=>'pages','action' => 'home']);
        }
        $isAdd=$this->Authorization->can($deviationsEntity, 'add')?1:0;
        $access_module=$this->request->getSession()->read('Auth')['customer_role']['rolewise_permissions'];
        $isATrue=false;
        //debug($isPlanned.'   '.$is);die;
        $devName= '';
        if($isPlanned == 'dev'){
            
            $devName = $isPlanned;
            $isPlanned = 'un';
            
        }
       
        $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
        $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        $devIsPlanned="";
        if($isPlanned=="un"){
            $devIsPlanned="unplanned";
        }else{
            $devIsPlanned="planned";
        }
        
        $this->loadComponent('WiseWorks');
        $allSteps=$this->WiseWorks->getAllSteps();
        
        $statusList=array();
        
        foreach ($allSteps as $steps)
        {
            $statusList[$steps->status_master_id]=$steps->display_name;
        }
        
         if (isset($statusList)) {
             $list=$statusList;
             $stat = array_keys(array_slice($list, -1, 1, true));
             $statusLimit = array_pop($stat);
        }
        else {
            $statusLimit=0;
        }
        
        //debug($statusList);die;
      
//         $this->loadModel('DeviationConfiguration');
//         $devConfig=$this->DeviationConfiguration->find('all',['conditions'=>['DeviationConfiguration.customer_id'=>$customer_id,'DeviationConfiguration.deviation_type'=>$devIsPlanned]])->last();
         //         if(!empty($devConfig)){
//             if($devConfig['qa_closure_required']){
//                 $statusLimit=13;
//             }
//         }
        $condition[]["AND"]=["(Deviation.created_by_customer=".$customer_id." OR (Deviation.customer_id=".$customer_id." AND Deviation.dev_status_master_id < ".$statusLimit."))"];
        
//         $condition[]["AND"]=["Deviation.customer_id"=>$customer_id,'Deviation.dev_status_master_id <'=>$statusLimit];
//         $devIsPlanned="";
//         if($isPlanned=="un"){
//             $condition[]["AND"]=["Deviation.isplanned"=>2];
//             $devIsPlanned="unplanned";
//         }else{
//             $condition[]["AND"]=["Deviation.isplanned"=>1];
//             $devIsPlanned="planned";
//         }
        $customerLocationData=$this->request->getQuery('customer_locations_id');
        $statusData=$this->request->getQuery('status_master_id');
        $createdData=$this->request->getQuery('created');
        $referenceNumberData=$this->request->getQuery('reference_number');
        
        if($customerLocationData != ""){ $condition["AND"][]=['OR'=>["Deviation.customer_locations_id"=>$customerLocationData]]; }
        if($statusData != ""){ $condition["AND"][]=['OR'=>["Deviation.dev_status_master_id"=>$statusData]]; }
        if($createdData != ""){ $condition["AND"][]=['OR'=>["Deviation.created Like '%$createdData%' "]]; }
        if($referenceNumberData != ""){ $condition["AND"][]=['OR'=>["Deviation.reference_number Like '%$referenceNumberData%' "]]; }
        $this->paginate = [
            "contain" => ['CreatedByUser'=>['fields'=>['userfullname']],
                'DevInvestigation','DevTargetExtension',
                'DevStatusLog'=>[
                "sort"=>["DevStatusLog.id"=>"Desc"],
                "conditions"=>["DevStatusLog.action_taken != 'Reject'"]],
            'CustomerLocations'=>["fields"=>["name"]]],
            "conditions"=>$condition,
            "order"=>["id"=>"desc"]
        ];
        $deviation = $this->paginate($this->Deviation);
        
        $alldeviation = $this->Deviation->find('all', [
            'contain' =>['CreatedByUser'=>['fields'=>['userfullname']],
                'DevInvestigation','DevTargetExtension',
                'DevStatusLog'=>[
                    "sort"=>["DevStatusLog.id"=>"Desc"],
                    "conditions"=>["DevStatusLog.action_taken != 'Reject'"]],
                'CustomerLocations'=>["fields"=>["name"]]],
            "conditions" => $condition,
            "order"=>["Deviation.id"=>"desc"]
        ])->toArray();
        
        foreach ($deviation as $key=>$dev){
            $appliacableSteps =$this->WiseWorks->getAllStepsForModel($dev->id);
            $dev->applicable_stpes=$appliacableSteps;
        }
        foreach ($alldeviation as $key=>$dev){
            $appliacableSteps =$this->WiseWorks->getAllStepsForModel($dev->id);
            $dev->applicable_stpes=$appliacableSteps;
        }
        //debug($alldeviation);die;
        
//         $this->loadModel("DeviationConfiguration");
//         $davConfigStatusIdArray=$this->DeviationConfiguration->find('all',['conditions'=>['DeviationConfiguration.customer_id'=>$customer_id,'DeviationConfiguration.deviation_type'=>$devIsPlanned]])->last();
//         $davConfigStatusId=!empty($davConfigStatusIdArray['status_id'])?$davConfigStatusIdArray['status_id']:'0';
         $devStatusMaster=$this->Deviation->DevStatusMaster->find('all')->toArray();
        $customerLocationsList = $this->Deviation->CustomerLocations->find('list', ['keyField' => 'id','valueField' => 'name'])->where(["CustomerLocations.customer_id"=>$this->request->getSession()->read("Auth")->get("customer_id")])->toArray();
        $this->set(compact('appliacableSteps','allSteps','customerLocationsList','statusList','referenceNumberData','createdData','statusData','customerLocationData','isAdd','deviation','devStatusMaster','isPlanned','devName','loggedUserId','alldeviation'));
        
        if ($this->request->getQuery('export') != null) {
            $conn = $this;
            $CallBackStream = new CallbackStream(function () use ($conn,$alldeviation) {
                try {
                    $conn->viewBuilder()->setLayout("xls");
                    $conn->set(compact('alldeviation'));
                    echo $conn->render('indexexport');
                } catch (Exception $e) {
                    echo $e->getMessage();
                    $e->getTrace();
                }
            });
                return $this->response->withBody($CallBackStream)
                ->withAddedHeader("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
                ->withAddedHeader("Content-disposition", "attachment; filename=Deviation List.xls");
                
        }
    }
    
    /**
     * View method
     *
     * @param string|null $id Deviation id.
     * @return \Cake\Http\Response|null
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null,$isCheck=false)
    {
        $id=($id==null)?null:decryptVal($id);
        $isCheck=($isCheck==false)?false:decryptVal($isCheck);
        if($isCheck){
            if(!$this->Authorization->can($deviation, 'view')){
                $this->Flash->error(__('You dont have permission to view deviation.'));
                return $this->redirect(['action' => 'index']);
            }
        }else{
            $this->Authorization->skipAuthorization();
        }
        $this->getDeviationData($id);
        $this->loadModel('DevAttachments');
        $condition_file=array(
            "dev_id"=>$id,
            "doc_step_attachment"=>'investigate_doc'
        );
        $investigateAttachmentfile = $this->DevAttachments->find("all",["conditions"=>$condition_file])->toArray();
        $condition_file=array(
            "dev_id"=>$id,
            "doc_step_attachment"=>'close_doc'
        );
        $closeAttachmentfile = $this->DevAttachments->find("all",["conditions"=>$condition_file])->toArray();
        $this->set('closeAttachmentfile',$closeAttachmentfile);
        $this->set('investigateAttachmentfile',$investigateAttachmentfile);
        $this->viewBuilder()->setTemplate("view");
    }
    
    /**
     * Add method
     *
     * @return \Cake\Http\Response|null Redirects on successful add, renders view otherwise.
     */
    public function add($isPlanned=null)
    {
        $deviation = $this->Deviation->newEmptyEntity();
        $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
        $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        
        $loggedUserDeptId=$this->request->getSession()->read('Auth')['departments_id'];
        $plugin=$this->request->getParam('plugin');
        $controller=$this->request->getParam('controller');
        $method=$this->request->getParam('action');
        $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
        
        $this->loadComponent('Common');
        
        if(!$this->Authorization->can($deviation, 'add')){
            $this->Flash->error(__('You dont have permission to create deviation.'));
            return $this->redirect(['action' => 'index']);
        }
        
        
        $this->loadComponent('WiseWorks');
        $current_status_id = 1;
        $allSteps=$this->WiseWorks->getAllSteps();
        $status_step = $this->WiseWorks->getSingleStep($current_status_id,$allSteps);
        $nextStatusId = $status_step['next_step_status_master_id'];
        $this->getTransPassData($status_step);
        //end 
        
        //DevSettingsDataForAdd
        $this->loadModel("DevSettings");
        $devSettings = $this->DevSettings->find("list",['keyField'=>"element_name", "valueField"=>"properties", 'conditions' => array('DevSettings.customer_id' => $customer_id, "DevSettings.customer_locations_id"=>$customer_location_id)])->toArray();
        
        if ($this->request->is('post')) {
            $requestData=$this->request->getData();
            $requestData['dept_id'] = $loggedUserDeptId;
            $requestData['target_date']=date('Y-m-d',strtotime($requestData['target_dates']));
            $dev_attachments=$requestData['dev_attachments'];
            $devMoveUpload = $requestData['dev_attachments'];
            $approver_array=!empty($requestData['access'])?$requestData['access']:'';
            if($approver_array != ''){
                $requestData['dev_access'][]=['approver_id'=>$approver_array,'access_for'=>'dbmApproval'];
            }
            unset($requestData['dev_attachments']);
            foreach($dev_attachments as $j=>$dev_attachment){
                if(isset($dev_attachment["file_name"]) && $dev_attachment["file_name"]!=""){
                    $attachments=$dev_attachment["file_name"];
                        if($attachments->getError() == 0){
                            $filenm=$attachments->getClientFilename();
                            $fname = preg_replace('/\s+/', '', $filenm);
                            $filename=date("YmdHis").trim($fname);
                           $dev_attachments[$j]['file_name']=$filename;
                           $dev_attachments[$j]['doc_step_attachment']='dev_initial_doc';
                    }else{
                        unset($dev_attachments[$j]);
                    }
                }
            }
            $requestData['dev_attachments']=$dev_attachments;
            $dev_status_log=$requestData['dev_status_log'];
            unset($requestData['dev_status_log']);
            if(trim($requestData['removePlans'])!=""){
                $this->loadModel('Dev.DevActionPlans');
                $deleteDeviationPlans=$this->DevActionPlans->find("all",[
                    "conditions"=>[ "id IN ( ".$requestData['removePlans']." )"],
                ])->toArray();
                if(!empty($deleteDeviationPlans) && count($deleteDeviationPlans) > 0){
                    $this->loadComponent('Common');
                    $this->Common->auditTrailForDeleteAll(array_column($deleteDeviationPlans,'id'),'dev_action_plans','deviation',$id);
                    unset($requestData['removePlans']);
                }
            }
           
            $deviation = $this->Deviation->patchEntity($deviation, $requestData);
          // debug($deviation);die;
            if($isPlanned=="un"){
                $deviation["isplanned"]=2;
            }else{
                $deviation["isplanned"]=1;
            }
            $result=$this->Deviation->save($deviation);
            if ($result) {
                //dev_investigation
                $devid = $result->id;
                $this->loadModel('DevInvestigation');
                $DevInvestigation = $this->DevInvestigation->newEmptyEntity();                
                $DevinvData = [];                
                $DevinvData['dev_id']=$devid;                
                $DevInv = $this->DevInvestigation->patchEntity($DevInvestigation, $DevinvData);
                $this->DevInvestigation->save($DevInv);
                
            //dev_action_plan_log
            
                //debug($result['dev_action_plans'][0]);die;
                $this->loadModel('DevActionPlanLog');
                $DevActionPlanLog = $this->DevActionPlanLog->newEmptyEntity();
                
                    $actionPlanLogsData = [];
                
                $actionPlanLogsData['dev_action_plan_id'] = $result['dev_action_plans'][0]['id'];
                $actionPlanLogsData['dev_id'] = $result['dev_action_plans'][0]['dev_id'];
                $actionPlanLogsData['status'] = $result['dev_action_plans'][0]['status'];
                $actionPlanLogsData['created_by'] = $loggedUserId;
                   
                $DevActionPlanLog = $this->DevActionPlanLog->patchEntity($DevActionPlanLog, $actionPlanLogsData);
                $this->DevActionPlanLog->save($DevActionPlanLog);
                
                //End dev_action_plan_log
                foreach($devMoveUpload as $j=>$dev_attachment){
                    if(isset($dev_attachment["file_name"]) && $dev_attachment["file_name"]!=""){
                        $attachments=$dev_attachment["file_name"];
                        if($attachments->getError() == 0){
                            $filename =  $dev_attachments[$j]['file_name'];
                            $tmp_name=$attachments->getStream()->getMetadata('uri');
                            $deviationId=$deviation['id'];
                            $filesPathNew="deviation/".$deviationId.DS.$filename;
                            if(QMSFile::fileExistscheck($filesPathNew)){
                                QMSFile::moveUploadedFile($tmp_name,"deviation/".$deviationId.DS.$filename,$customer_id);
                            } else{
                                QMSFile::moveUploadedFile($tmp_name,"deviation/".$deviationId.DS.$filename,$customer_id);
                            }
                            QMSFile::moveUploadedFile($tmp_name,"deviation/".$deviationId.DS.$filename,$customer_id);
                        }else{
                            unset($dev_attachments[$j]);
                        }
                    }
                }
               
                $dev_id=$result->get('id');
                $dev_status_log['dev_id']=$dev_id;
                $this->loadModel("Dev.DevStatusLog");
                if(isset($requestData['submitVerify'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                    }
                }
                $devStatusLog = $this->DevStatusLog->newEmptyEntity();
                $devStatusLog = $this->DevStatusLog->patchEntity($devStatusLog, $dev_status_log);
//                debug($deviation);die;
                $this->DevStatusLog->save($devStatusLog);
                
                if(isset($requestData['submitVerify'])){
                    //added by shrirang
                    $prevStatusId =$current_status_id;
                    $current_status_id = $nextStatusId;
                    $nextStatusId = $this->WiseWorks->getNextStep($nextStatusId,$dev_id);
                    
                    $title = $result->reference_number." Deviation Initiated";
                    $notificationData = [
                        'selected_users' => $devStatusLog['next_action_by'],
                        'reference_number' =>$result->reference_number,
                        'title' =>$title,
                        'against'=>$result->against,
                        'target_date'=>$result->target_date,
                        'customer_id'=>$customer_id,
                        'created_by'=>$loggedUserId,
                        'notification_identifier'=>'dev_initiated',
                        'id' =>$dev_id,
                        'current_status_id'=>$current_status_id,
                        'nextStatusId'=>$nextStatusId,
                        'prevStatusId'=>$prevStatusId
                    ];
                    
                    $this->loadComponent('CommonMailSending');
                    $this->CommonMailSending->selected_email_details($customer_id,$customer_location_id,$loggedUserId,$notificationData);
                    $this->loadComponent('QmsNotification');
                    $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    //Ended by here by shrirang
                }
                
                $this->Flash->success(__('The deviation has been saved.'));
                
                return $this->redirect(["controller"=>"Deviation",'action' => 'index']);
            }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        $customerLocations = $this->Deviation->CustomerLocations->find('list', ['keyField' => 'id','valueField' => 'name'])->where(["CustomerLocations.customer_id"=>$customer_id]);
        $productMaster = $this->Deviation->ProductsMaster->find('list', ['keyField' => 'id','valueField' => 'product_name'])->where(["customer_id"=>$customer_id,"product_service_type"=>"Product"])->toArray();
        $processMaster = $this->Deviation->ProcessMaster->find('list', ['keyField' => 'id','valueField' => 'process_name'])->where(["customer_id"=>$customer_id])->toArray();
        $department = $this->Deviation->FoundAtDept->find('list', ['keyField' => 'id','valueField' => 'department'])->where(['FoundAtDept.customer_id'=>$customer_id]);
        
        $productLineMasters = $this->Deviation->ProductionLineMaster->find('list', ['keyField' => 'id','valueField' => 'prod_line'])->where(["customer_id"=>$customer_id])->toArray();
      
       // $devArray=Configure::read("devArray");
       
        $this->loadModel("OtherMaster");
        $source_type = $this->OtherMaster->find('list', ['keyField' => 'name','valueField' => 'name'])->where(["customer_id"=>$customer_id,"location_id"=>$customer_location_id,"plugin_name"=>"Dev","type"=>"source_type","active"=>1])->toArray();
       
        //get OtherMaster system type add list Against DropDown
        $this->loadModel("OtherMaster");
        $otherMasters = $this->OtherMaster->find('list',[
            'keyField' => 'id',
            'valueField' => 'name',
            'groupField' => 'type'
        ])->where(["customer_id"=>$customer_id,"location_id"=>$customer_location_id,'plugin_name'=>$plugin,"active"=>1])->toArray();
        
           $arr=array("Process"=>$processMaster,"Product"=>$productMaster);
           
           $otherMasters=array_merge($otherMasters,$arr);
         
           foreach ($otherMasters as $key =>$val)
           {
               $val = str_replace("'", '', $val);
               $otherMasters[$key]=$val;
           }
           $cr_againstList = array();
           foreach($otherMasters as $key=>$value){
               $cr_againstList[$key]=$key;
           }
           
           //debug($cr_againstList);die;
  
        $this->loadModel("CustomerVendors");
        $suppliersList=$this->CustomerVendors->find('all',[
            'contain'=>['CustomerVendor'],
            'conditions'=>["customer_id=".$customer_id]
        ]);
        $suppliers=$suppliersList->map(function($value,$key){
            return [
                'value'=>$value->vendor_id,
                'text'=>$value->customer_vendor->company_name,
            ];
        });
            
            $customersList=$this->CustomerVendors->find('all',[
                'contain'=>['CustomerVendor'],
                'conditions'=>"vendor_id=".$customer_id
            ]);
            $customers=$customersList->map(function($value,$key){
                return [
                    'value'=>$value->customer_id,
                    'text'=>$value->customer_vendor->company_name,
                ];
            })->toList();
            $this->loadModel("Users");
            $userCondition=array("Users.customer_id"=>$customer_id,"Users.active"=>1);
            $users=$this->Common->getUsersArray($userCondition);
            $dbmUsers = $this->Users->find('list', ['keyField' => 'id','valueField' => 'full_name'])->where(array_merge($userCondition,['Users.is_dept_head'=>1]))->toArray();
            $this->loadModel("DocMaster");
            $docMaster = $this->DocMaster->find('list', ['keyField' => 'id','valueField' => 'title'])->where(['customer_id'=>$customer_id,'customer_locations_id'=>$customer_location_id])->toArray();
            
            $this->loadModel("Uom");
            $uom = $this->Uom->find('list', ['keyField' => 'id','valueField' => 'unit_name'])->toArray();
            $devIsConfigPlanned=($isPlanned=="un")?"unplanned":"planned";
            $this->loadModel('DeviationConfiguration');
            $devConfig=$this->DeviationConfiguration->find('all',['conditions'=>['DeviationConfiguration.customer_id'=>$customer_id,'DeviationConfiguration.deviation_type'=>$devIsConfigPlanned]])->last();
            
            $userCondition=array("plugin"=>$plugin,"controller"=>$controller,"element_name"=>'department_head',"is_auth"=>1,'RolewisePermissions.can_approve'=>1);
            
            $deptHeadUsers=$this->Common->getUserDeptHead($userCondition,$loggedUserDeptId);
            
            $currentData = date('d-m-Y');
            $this->set(compact('productLineMasters','loggedUserId','currentData','deptHeadUsers','docMaster','isPlanned','dbmUsers','devConfig','uom','users','department','source_type','loggedUserId','customer_id','deviation', 'customers', 'customerLocations', 'suppliers', 'productMaster', 'processMaster','otherMasters','cr_againstList','status_step','current_status_id', 'devSettings'));
            $this->set('DateTimeFormat',$this->DateTimeFormat); //for passing variable to template
    }
    
    /**
     * Edit method
     *
     * @param string|null $id Deviation id.
     * @return \Cake\Http\Response|null Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null, $currentStatusId=null, $nextStatusId=null, $prevStatusId=null)
    {
        $id=($id==null)?null:decryptVal($id);
        $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
        $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
        $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
        
        $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
        $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        
        $loggedUserDeptId=$this->request->getSession()->read('Auth')['departments_id'];
        $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
        
        $plugin=$this->request->getParam('plugin');
        $controller=$this->request->getParam('controller');
        $method=$this->request->getParam('action');
        $this->loadComponent('Common');
        $deviation_data = $this->Deviation->get($id, [
            'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                    'DevActionPlans'=>['DevActionPlanLog'=>["Action_Modified_by","Action_Created_by"]],
            ],
        ]);
        $isplanned='';
        if($deviation_data['isplanned']==2){
            $isplanned="un";
            $planned="dev";
        }
        
        if(!$this->Authorization->can($deviation_data, 'edit')){
            $this->Flash->error(__('You dont have permission to update deviation.'));
            return $this->redirect(['action' => 'index']);
        }
        
        //added by shrirang
        $this->loadComponent('WiseWorks');
       // $current_status_id = 1;
        $allSteps=$this->WiseWorks->getAllSteps();
        $status_step = $this->WiseWorks->getSingleStep($currentStatusId,$allSteps);
        $this->getTransPassData($status_step);
        //end 
        
        //DevSettingsDataForEdit
        $this->loadModel("DevSettings");
        $devSettings = $this->DevSettings->find("list",['keyField'=>"element_name", "valueField"=>"properties", 'conditions' => array('DevSettings.customer_id' => $customer_id, "DevSettings.customer_locations_id"=>$customer_location_id)])->toArray();
        
        if ($this->request->is(['patch', 'post', 'put'])) {
            $requestData=$this->request->getData();//debug($requestData);die; 
            $dev_attachments=$requestData['dev_attachments'];
            $dev_status_log=$requestData['dev_status_log'];
            $dev_status_log["actual_start_date"]=date($this->DateTimeFormat);
            $approver_array=!empty($requestData['access'])?$requestData['access']:'';
            $requestData['target_date']=$requestData['target_dates'];
            
            // $plugin = NULL;
            $this->loadComponent('Common');
            $this->Common->close_notification($plugin,'Deviation',$id);
            
            
            unset($requestData['dev_attachments']);
            foreach($dev_attachments as $j=>$dev_attachment){
                if(isset($dev_attachment["file_name"]) && $dev_attachment["file_name"]!=""){
                    $attachments=$dev_attachment["file_name"];
                    if($attachments->getError()==0){
                        $filename=date("YmdHis").$attachments->getClientFilename();
                        $tmp_name=$attachments->getStream()->getMetadata('uri');
                        $deviationId=$deviation_data['id'];
                        $filesPathNew="deviation/".$deviationId.DS.$filename;
                        if(QMSFile::fileExistscheck($filesPathNew)){
                            QMSFile::moveUploadedFile($tmp_name,"deviation/".$deviationId.DS.$filename,$customer_id);
                        } else{
                            QMSFile::moveUploadedFile($tmp_name,"deviation/".$deviationId.DS.$filename,$customer_id);
                        }
                        QMSFile::moveUploadedFile($tmp_name,"deviation/".$deviationId.DS.$filename,$customer_id);
                        $dev_attachments[$j]['file_name']=$filename;
                    }else{
                        //unset($dev_attachments[$j]['file_name']);
                        unset($dev_attachments[$j]);
                    }
                }
            }
            $requestData['dev_attachments']=$dev_attachments;
            
            if(trim($requestData['removeImpactedItem'])!=""){
                $this->loadModel('Dev.DevImpactedItem');
                $deleteDeviationItem=$this->DevImpactedItem->find("all",[
                    "conditions"=>[ "id IN ( ".$requestData['removeImpactedItem']." )"],
                ])->toArray();
                if(!empty($deleteDeviationItem) && count($deleteDeviationItem) > 0){
                    $this->loadComponent('Common');
                    $this->Common->auditTrailForDeleteAll(array_column($deleteDeviationItem,'id'),'dev_impacted_item','deviation',$id);
                    unset($requestData['removeImpactedItem']);
                }
            }
            if(trim($requestData['removeDocs'])!=""){
                $this->loadModel('Dev.DevAttachments');
                $deleteChangeAttachments=$this->DevAttachments->find("all",[
                    "conditions"=>[ "id IN ( ".$requestData['removeDocs']." )"],
                ])->toArray();
                if(!empty($deleteChangeAttachments) && count($deleteChangeAttachments) > 0){
                    $this->loadComponent('Common');
                    $this->Common->auditTrailForDeleteAll(array_column($deleteChangeAttachments,'id'),'dev_attachments','deviation',$id);
                    foreach($deleteChangeAttachments as $attachments){
                        if(trim($attachments['file_name'])!=""){
                            $filesPathNew="deviation".DS.$attachments['file_name'];
                            QMSFile::delete($filesPathNew,$customer_id);
                            //debug($filesPathNew);true;
                        }
                    }
                    $this->DevAttachments->deleteAll(["DevAttachments.id IN"=>explode(',',$requestData['removeDocs'])]);
                    unset($requestData['removeDocs']);
                }
            }
            if(trim($requestData['removePlans'])!=""){
                $this->loadModel('Dev.DevActionPlans');
                $deleteDeviationPlans=$this->DevActionPlans->find("all",[
                    "conditions"=>[ "id IN ( ".$requestData['removePlans']." )"],
                ])->toArray();
                if(!empty($deleteDeviationPlans) && count($deleteDeviationPlans) > 0){
                    $this->loadComponent('Common');
                    $this->Common->auditTrailForDeleteAll(array_column($deleteDeviationPlans,'id'),'dev_action_plans','deviation',$id);
                    unset($requestData['removePlans']);
                }
            }
           
            $deviation_data = $this->Deviation->patchEntity($deviation_data, $requestData); 
            $save = $this->Deviation->save($deviation_data);
            
            if ($save) {
                
                $dev_id=$id;
                
                $this->loadModel('DevActionPlanLog');
               
                foreach ($save['dev_action_plans'] as $key => $value){
                    
                    if(!empty($value['dev_action_plan_log'][$key]['id'])){
                        
               
                        $DevActionPlanLog = $this->DevActionPlanLog->get($value['dev_action_plan_log'][$key]['id']);
                        
                        $actionPlanLogsData = [];
                        
                        $actionPlanLogsData['id'] = $value['dev_action_plan_log'][$key]['id'];
                        $actionPlanLogsData['modified_by'] = $loggedUserId;
                        
                    }else{
                        
                       
                        $DevActionPlanLog = $this->DevActionPlanLog->newEmptyEntity();
                        
                        $actionPlanLogsData = [];
                        
                        $actionPlanLogsData['dev_action_plan_id'] = $value['id'];
                        $actionPlanLogsData['dev_id'] = $dev_id;
                        $actionPlanLogsData['status'] = 'Pending';
                        $actionPlanLogsData['created_by'] = $loggedUserId;
                      }
                    
                    
                        $DevActionPlanLog = $this->DevActionPlanLog->patchEntity($DevActionPlanLog, $actionPlanLogsData);
                        $this->DevActionPlanLog->save($DevActionPlanLog);
                   
                }
                
                //End dev_action_plan_log
                
                
                
                if(isset($requestData['submitVerify'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                    }
                }
                $status_log=$this->Deviation->DevStatusLog->get($dev_status_log['id']);
                $status_log=$this->Deviation->DevStatusLog->patchEntity($status_log,$dev_status_log);
                $this->Deviation->DevStatusLog->save($status_log);
                
                $this->loadModel("Dev.DevAccess");
                if($approver_array != ''){
                    $this->loadComponent('Common');
                    $accessids=$this->DevAccess->find('all',['conditions'=>['dev_id'=>$dev_id,'access_for'=>'dbmApproval']])->toArray();
                    if(!empty($accessids) && count($accessids) > 0){
                        $this->Common->auditTrailForDeleteAll(array_column($accessids,'id'),'dev_access','deviation',$dev_id);
                    }
                    $this->DevAccess->deleteAll(['dev_id'=>$dev_id,'access_for'=>'dbmApproval']);
                    //debug($approver_array);exit;
                    //foreach($approver_array as $forUserid){
                        $devAccess=$this->DevAccess->newEntity([
                            'dev_id'=>$dev_id,
                            'approver_id'=>$approver_array,
                            'access_for'=>'dbmApproval'
                        ]);
                        $this->DevAccess->save($devAccess);
                    //}
                }
                if(isset($requestData['submitVerify'])){
                    //added by shrirang
                    $prevStatusId =$currentStatusId;
                    $currentStatusId = $nextStatusId;
                    $nextStatusId = $this->WiseWorks->getNextStep($currentStatusId,$dev_id);
                    
                    $title = $save->reference_number." Deviation Initiated";
                    $notificationData = [
                        'selected_users' => $status_log['next_action_by'],
                        'reference_number' =>$save->reference_number,
                        'title' =>$title,
                        'against'=>$save->against,
                        'target_date'=>$save->target_date,
                        'customer_id'=>$customer_id,
                        'created_by'=>$loggedUserId,
                        'notification_identifier'=>'dev_initiated',
                        'id' =>$dev_id,
                        'current_status_id'=>$currentStatusId,
                        'nextStatusId'=>$nextStatusId,
                        'prevStatusId'=>$prevStatusId
                    ];
                    
                    $this->loadComponent('CommonMailSending');
                    $this->CommonMailSending->selected_email_details($customer_id,$customer_location_id,$loggedUserId,$notificationData);
                    $this->loadComponent('QmsNotification');
                    $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    //Ended by here by shrirang
                }
                
                $this->Flash->success(__('The deviation has been saved.'));
                
                return $this->redirect(["controller"=>"Deviation",'action' => 'index']);
            }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        
        $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>1,'DevStatusLog.action_taken !='=>'Reject'])->last();
        
        $userCondition=array("plugin"=>$plugin,"controller"=>$controller,"element_name"=>'department_head',"is_auth"=>1,'RolewisePermissions.can_approve'=>1);
        
        $deptHeadUsers=$this->Common->getUserDeptHead($userCondition,$loggedUserDeptId);
        $currentData = CustomerCache::read("dateinputformat");
        $this->set(compact('currentData','deptHeadUsers','currentLog', 'currentStatusId', 'nextStatusId', 'prevStatusId','status_step', 'devSettings'));
        $this->getDeviationData($id,true);
        $this->viewBuilder()->setTemplate("edit");
    }
    
    public function getDeviationData($id = null,$deviation_status_id=null)
    {
        $plugin=$this->request->getParam('plugin');
        $Current_status = $deviation_status_id;
        $deviation_status_id=($deviation_status_id==null)?null:decryptVal($deviation_status_id);
        
        $deviation = $this->Deviation->get($id, [
            'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                'DevStatusLog'=>[
                    'DevStatusMaster'=>['fields'=>['display_status']],
                    'DevStatusChangeBy'=>['CustomerRoles','fields'=>['userfullname']], 
                    'DevNextActionBy'=>['fields'=>['userfullname']],
                    "sort"=>["DevStatusLog.id"=>"ASC"],
                ],
                'DeviationDuplicate'=>['DuplicateDeviation'],
                'CustomerLocations'=>["fields"=>["name"]],
                'ProductsMaster','ProcessMaster','FoundAtDept',"DevAttachments"=>['conditions'=>['doc_step_attachment IS'=>'dev_initial_doc']],
                "DevImpactedItem"=>['ProductsMaster','Uom'],
                "ProductionLineMaster","DevAssessment","DevStatusMaster",
                "DevActionPlans"=>['DevActionPlanAttachment','ActionAssignedToCm','DevActionPlanLog'=>["Action_Modified_by","Action_Created_by"]],
                "InitContActionBy"=>["fields"=>['userfullname']],
                "ImmediateActionBy"=>["fields"=>['userfullname']],
                "DevAccess","DevContainment"=>['PerformedBy','ApprovedBy'],
                'DevDisposition'=>['PerformedBy','ApprovedBy'],
                'DevTargetExtension'=>["ActionByUser",'CreatedByUser'],"DevCustomerApproval"
            ],
        ]);
//         debug($deviation);die;
        $this->loadComponent('Common');
        $customer_id=$deviation['customer_id'];
        $customer_location_id=$deviation['customer_locations_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        $createdBy=!empty($deviation['CreatedByUser'])?$deviation['CreatedByUser']['userfullname']:'';
        
        $customerLocations = $this->Deviation->CustomerLocations->find('list', ['keyField' => 'id','valueField' => 'name'])->where(["CustomerLocations.customer_id"=>$customer_id]);
        $productMaster = $this->Deviation->ProductsMaster->find('list', ['keyField' => 'id','valueField' => 'product_name'])->where(["customer_id"=>$customer_id,"product_service_type"=>"Product"])->toArray();
        $processMaster = $this->Deviation->ProcessMaster->find('list', ['keyField' => 'id','valueField' => 'process_name'])->where(["customer_id"=>$customer_id])->toArray();
        $department = $this->Deviation->FoundAtDept->find('list', ['keyField' => 'id','valueField' => 'department'])->where(['FoundAtDept.customer_id'=>$customer_id])->toArray();
        $productLineMasters = $this->Deviation->ProductionLineMaster->find('list', ['keyField' => 'id','valueField' => 'prod_line'])->where(["customer_id"=>$customer_id])->toArray();
       $devArrayData=Configure::read("devArray");
       //debug($devArray);die;
        $this->loadModel("OtherMaster");
        $devArray = $this->OtherMaster->find('list', ['keyField' => 'name','valueField' => 'name'])->where(["customer_id"=>$customer_id,"location_id"=>$customer_location_id,"plugin_name"=>"Dev","type"=>"source_type","active"=>1])->toArray();
        //get OtherMaster system type add list Against DropDown
        $this->loadModel("OtherMaster");
        $otherMasters = $this->OtherMaster->find('list',[
            'keyField' => 'id',
            'valueField' => 'name',
            'groupField' => 'type'
        ])->where(["customer_id"=>$customer_id,'plugin_name'=>$plugin])->toArray();
        
        $arr=array("Process"=>$processMaster,"Product"=>$productMaster);
        $otherMasters=array_merge($otherMasters,$arr);
        foreach ($otherMasters as $key =>$val)
        {
            $val = str_replace("'", '', $val);
            $otherMasters[$key]=$val;
        }
        $cr_againstList = array();
        foreach($otherMasters as $key=>$value){
            $cr_againstList[$key]=$key;
        }
        
        $this->loadComponent('WiseWorks');
        $allSteps=$this->WiseWorks->getAllSteps();
        $appliacableSteps =$this->WiseWorks->getAllStepsForModel($deviation->id);
        
        if($Current_status==null){
            $lastStatusLog = end($deviation['dev_status_log']);
            $status = ($lastStatusLog['dev_status_master_id']);
        }else{
            $status = $Current_status;
        }
        $requiredcell = $this->WiseWorks->getSingleStep($status,$allSteps);
        
        $this->loadModel("CustomerVendors");
        $suppliersList=$this->CustomerVendors->find('all',[
            'contain'=>['CustomerVendor'],
            'conditions'=>["customer_id=".$customer_id]
        ]);
        $suppliers=$suppliersList->map(function($value,$key){
            return [
                'value'=>$value->vendor_id,
                'text'=>$value->customer_vendor->company_name,
            ];
        });
            
            $customersList=$this->CustomerVendors->find('all',[
                'contain'=>['CustomerVendor'],
                'conditions'=>"vendor_id=".$customer_id
            ]);
            $customers=$customersList->map(function($value,$key){
                return [
                    'value'=>$value->customer_id,
                    'text'=>$value->customer_vendor->company_name,
                ];
            })->toList();
            $userCondition=array("Users.customer_id"=>$customer_id,"Users.active"=>1);
            $this->loadModel("Users");
            $users=$this->Common->getUsersArray($userCondition);
        
            $this->loadModel("DevStatusMaster");
            $deviation_statuses = $this->DevStatusMaster->find('list', ['keyField' => 'id','valueField' => 'display_status'])->toArray();
            
            $statusMaster = $this->DevStatusMaster->find('all');
            
            $this->set('deviation_statuses', $deviation_statuses); 
            
            
            
            $nextuserCondition=array("Users.customer_id"=>$customer_id,"Users.active"=>1);
            $nextApprovalList = $this->Users->find('list', ['keyField' => 'id','valueField' => 'full_name'])->where($nextuserCondition)->toArray();
            
            $approverUsers = $this->Users->find('list', ['keyField' => 'id','valueField' => 'full_name'])->where(array_merge($userCondition,['Users.is_approver'=>1]))->toArray();
            $dbmUsers = $this->Users->find('list', ['keyField' => 'id','valueField' => 'full_name'])->where(array_merge($userCondition,['Users.is_dept_head'=>1]))->toArray();
            $this->loadModel("ProductionLineMaster");
            $prodlineMaster = $this->ProductionLineMaster->find('list', ['keyField' => 'id','valueField' => 'prod_line'])->where(['ProductionLineMaster.customer_id'=>$customer_id,'ProductionLineMaster.customer_locations_id'=>$customer_location_id])->toArray();
            $this->loadModel("Uom");
            $uom = $this->Uom->find('list', ['keyField' => 'id','valueField' => 'unit_name'])->toArray();
            
            $devConfigPlanned=($deviation['isplanned']==1)?"planned":"unplanned";
            $this->loadModel('DeviationConfiguration');
            $devConfig=$this->DeviationConfiguration->find('all',['conditions'=>['DeviationConfiguration.customer_id'=>$customer_id,'DeviationConfiguration.deviation_type'=>$devConfigPlanned]])->last();
            $davConfigStatusIdArray=$this->DeviationConfiguration->find('all',['conditions'=>['DeviationConfiguration.customer_id'=>$customer_id,'DeviationConfiguration.deviation_type'=>$devConfigPlanned]])->last();
            $davConfigStatusId=!empty($davConfigStatusIdArray['status_id'])?$davConfigStatusIdArray['status_id']:'0';
            $devStatusMaster=$this->Deviation->DevStatusMaster->find('all')->toArray();
            
            
            $this->loadModel("DocMaster");
            $docMaster = $this->DocMaster->find('list', ['keyField' => 'id','valueField' => 'title'])->where(['customer_id'=>$customer_id,'customer_locations_id'=>$customer_location_id])->toArray();
            
            $dbmAccess=$this->Deviation->DevAccess->find('list',['keyField'=>'approver_id','valueField'=>'approver_id'])->where(['dev_id'=>$id,'access_for'=>'dbmApproval'])->toArray();
            $this->loadModel("Dev.DevInvestigation");
            $devInvestigation=$this->DevInvestigation->find('all')->where(['dev_id'=>$id])->first();
            
            $this->loadModel("Dev.DevImpactedList");
            $devImpactedList=$this->DevImpactedList->find('all')->where(['dev_id'=>$id])->toArray();
            
            $this->loadModel("Dev.DevActionPlans");
            $devactionplanscount=$this->DevActionPlans->find('all')->where(['dev_id'=>$id,'status !='=>'Close'])->count();
            $DevAssessment= $this->Deviation->DevAssessment->find("all")
            ->where(["dev_id"=>$id])->first();
            $DevFinalAssessment= $this->Deviation->DevAssessment->find("all")
            ->where(["dev_id"=>$id,"type"=>'Final'])->last();
            
            $this->loadModel("capa");
            $capa= $this->capa->find("all")
            ->where(["plugin"=>"Deviation","model_reference_id"=>$id])
            ->first();
            
            $method=$this->request->getParam('action');
            $this->loadModel("Customer");
            $supplier = $this->Customer->find('list', ['keyField' => 'id','valueField' => 'company_name'])->toArray();
            $this->set('company_name', $supplier);
            $this->set(compact('devactionplanscount','productMaster','productLineMasters','DevFinalAssessment','nextApprovalList','DevAssessment','docMaster','dbmAccess','dbmUsers','approverUsers','devStatusMaster','devConfig','prodlineMaster','uom','users','department','devArray','loggedUserId','customer_id','deviation', 'customers', 'customerLocations', 'suppliers','devInvestigation','devImpactedList','otherMasters','cr_againstList','devArrayData','requiredcell','capa'));

            $this->set('appliacableSteps', $appliacableSteps);
            $this->set('method', $method);
            
    }
    /**
     * Delete method
     *
     * @param string|null $id Deviation id.
     * @return \Cake\Http\Response|null Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $id=($id==null)?null:decryptVal($id);
        
        $this->request->allowMethod(['post', 'delete']);
        $deviation = $this->Deviation->get($id);
        if ($this->Deviation->delete($deviation)) {
            $this->Flash->success(__('The deviation has been deleted.'));
        } else {
            $this->Flash->error(__('The deviation could not be deleted. Please, try again.'));
        }
        
        return $this->redirect(['action' => 'index']);
    }
    
    public function approvedbm($id = null, $currentStatusId=null, $nextStatusId=null, $prevStatusId=null)
    {
        $id=($id==null)?null:decryptVal($id);
        $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
        $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
        $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
        $deviation_data = $this->Deviation->get($id, [
            'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                'DevStatusLog'=>['DevNextActionBy'=>['fields'=>['userfullname']]],
                "DevAccess","FoundAtDept","DevTargetExtension",
            ],
        ]);
        $isplanned='';
        if($deviation_data['isplanned']==2){
            $isplanned="un";
        }
        $customer_id=$deviation_data['customer_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];        
        $loggedUserDeptId=$this->request->getSession()->read('Auth')['departments_id'];
        $plugin=$this->request->getParam('plugin');
        $controller=$this->request->getParam('controller');
        $method=$this->request->getParam('action');
        $this->loadComponent('Common');
        $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
        //$createdBy=!empty($deviation_data['CreatedByUser'])?$deviation_data['CreatedByUser']['userfullname']:'';
        //$lastrecord=!empty($deviation_data['dev_status_log'])?count($deviation_data['dev_status_log']):0;
        //$verifier=($lastrecord>0)?!empty($deviation_data['dev_status_log'][$lastrecord-1]['DevNextActionBy'])?$deviation_data['dev_status_log'][$lastrecord-1]['DevNextActionBy']['userfullname']:'':'';
        $deviation_data->previousStepId = $prevStatusId ;
        // debug($deviation_data);die;
        if(!$this->Authorization->can($deviation_data, 'approvedbm')){
            if($deviation_data['dev_status_master_id'] >= 2){
                $this->Flash->error(__('Deviation is already approved by Department Head Members.'));
            }else{
                $this->Flash->error(__('Only selected Department Head Members have permission to approve deviation.'));
            }
            return $this->redirect(['action' => 'index']);
        }
        //added by shrirang
        $this->loadComponent('WiseWorks');
        $allSteps=$this->WiseWorks->getAllSteps();
        $status_step = $this->WiseWorks->getSingleStep($currentStatusId,$allSteps);
        $this->getTransPassData($status_step);
        //ended here
        
        //DevSettingsDataForApprovebdm
        $this->loadModel("DevSettings");
        $devSettings = $this->DevSettings->find("list",['keyField'=>"element_name", "valueField"=>"properties", 'conditions' => array('DevSettings.customer_id' => $customer_id, "DevSettings.customer_locations_id"=>$customer_location_id)])->toArray();

        if ($this->request->is(['patch', 'post', 'put'])) {
            $requestData=$this->request->getData();
            //debug($requestData['impact_on_production_activity']);die;
            $dev_status_log=$requestData['dev_status_log'];
            if(isset($requestData['submitApprove'])){
                $requestData['dev_status_master_id']=2;
                $requestData['dev_owner_id']=$dev_status_log['next_action_by'];
            }
            
            // $plugin = NULL;
            $this->loadComponent('Common');
            $this->Common->close_notification($plugin,'Deviation',$id);
            
            
            
            $deviation_data = $this->Deviation->patchEntity($deviation_data, $requestData);
            if ($result = $this->Deviation->save($deviation_data)) {
                $dev_id=$id;
                $status=$dev_status_log['action_taken'];
                if(isset($requestData['submitApprove'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                      
                        if(isset($dev_status_log['id']) && $dev_status_log['id'] != ''){
                            $status_log = $this->Deviation->DevStatusLog->get($dev_status_log['id']);
                        }else{
                            $status_log = $this->Deviation->DevStatusLog->newEmptyEntity();
                        }
                        
                        $status_log=$this->Deviation->DevStatusLog->patchEntity($status_log,$dev_status_log);
                       $this->Deviation->DevStatusLog->save($status_log);
                    }
                }
                if(isset($requestData['submitApprove'])){
                    //added by shrirang
                    $prevStatusId =$currentStatusId;
                    $current_status_id = $nextStatusId;
                    $nextStatussId = $this->WiseWorks->getNextStep($nextStatusId,$dev_id);
                    
                    $title = $result->reference_number." Head Approval done";
                    $notificationData = [
                        'selected_users' => $status_log['next_action_by'],
                        'reference_number' =>$result->reference_number,
                        'title' =>$title,
                        'category'=>$result->classification,
                        'against'=>$result->against,
                        'target_date'=>$result->target_date,
                        'customer_id'=>$customer_id,
                        'created_by'=>$loggedUserId,
                        'notification_identifier'=>'HeadApproval_done',
                        'id' =>$dev_id,
                        'current_status_id'=>$current_status_id,
                        'nextStatusId'=>$nextStatussId,
                        'prevStatusId'=>$prevStatusId
                    ];
                    
                    $this->loadComponent('CommonMailSending');
                    $this->CommonMailSending->selected_email_details($customer_id,$customer_location_id,$loggedUserId,$notificationData);
                    $this->loadComponent('QmsNotification');
                    $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    //Ended by here by shrirang
                }
                
                if(isset($requestData['submitReject'])){
                    $this->loadModel('DevStatusLog');
                    $this->loadModel('DevStatusMaster');
                    $rejectComment =$dev_status_log['next_action_by_comments'];
                    $lastuser= $this->DevStatusLog->find()->select(['action_by'])->where(['dev_id =' => $dev_id,"dev_status_master_id"=>1])->first();
                    $lastuserid=$lastuser->action_by;
                    $laststep= $this->DevStatusMaster->find()->select(['form_name','controller_name'])->where(["id"=>1])->first();
                    $controller=$laststep->controller_name;
                    $action=$laststep->form_name;
                    //added by shrirang
                   
                    $current_status_id = 1;
                    $nextStatussId = $this->WiseWorks->getNextStep($current_status_id,$dev_id);
                    $prevsStatusId = $this->WiseWorks->getPreviousStep($nextStatussId,$dev_id);

                    $title = $result->reference_number." Head Approval rejected";
                    $notificationData = [
                        'selected_users' => $lastuserid,
                        'reference_number' =>$result->reference_number,
                        'title' =>$title,
                        'against'=>$result->against,
                        'target_date'=>$result->target_date,
                        'customer_id'=>$customer_id,
                        'created_by'=>$loggedUserId,
                        'notification_identifier'=>'investigate',
                        'id' =>$dev_id,
                        'current_status_id'=>$current_status_id,
                        'nextStatusId'=>$nextStatussId,
                        'prevStatusId'=>$prevsStatusId
                    ];
                    
                    $this->loadComponent('CommonMailSending');
                    $this->CommonMailSending->selected_email_details($customer_id,$customer_location_id,$loggedUserId,$notificationData);
                    $this->loadComponent('QmsNotification');
                    $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    //Ended by here by shrirang
                    if(isset($dev_status_log['id']) && $dev_status_log['id'] != ''){
                        $lastLogId = $dev_status_log['id'];
                    }
                    else {
                        $lastLogId=null;
                    }
                    $this->rejectstatus($dev_id,$prevStatusId,$currentStatusId,$rejectComment,$lastLogId);
                    //return $this->redirect(['action' => 'rejectstatus',$dev_id,1,2]);
                }
                //}
                   
                else{
                $this->Flash->success(__('The deviation has been saved.'));
                }
                return $this->redirect(['action' => 'index']);
                }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>2,'DevStatusLog.action_taken !='=>'Reject',])->last();
        $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,'DevStatusLog.action_taken !='=>'Reject'])->last();
        
        $this->loadModel("Users");
        $approversCondition=array("plugin"=>$plugin,"controller"=>$controller,"element_name"=>"qa_approve","is_auth"=>1,'RolewisePermissions.can_approve'=>1,'CustomerRoles.customer_id'=>$customer_id);
        $approvers=$this->Common->getAuthList($approversCondition,$loggedUserDeptId);
        
        $this->set(compact('currentLog','preLog', 'currentStatusId', 'nextStatusId', 'prevStatusId','approvers','devSettings'));
        $this->getDeviationData($id,true);
        $this->viewBuilder()->setTemplate("approvedbm");
        $this->set('DateTimeFormat',$this->DateTimeFormat); //for passing variable to template
    }
    
    public function verifydev($id = null, $currentStatusId=null, $nextStatusId=null, $prevStatusId=null)
    {
        $id=($id==null)?null:decryptVal($id);
        $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
        $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
        $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
        $deviation_data = $this->Deviation->get($id, [
            'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                'DevStatusLog'=>['DevNextActionBy'=>['fields'=>['userfullname']]],
                "DevOwner"=>['fields'=>['userfullname']],"DevActionPlans","DevAssessment",
            ],
        ]);
        $isplanned='';
        if($deviation_data['isplanned']==2){
            $isplanned="un";
        }
        $customer_id=$deviation_data['customer_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        //$createdBy=!empty($deviation_data['CreatedByUser'])?$deviation_data['CreatedByUser']['userfullname']:'';
        //$lastrecord=!empty($deviation_data['dev_status_log'])?count($deviation_data['dev_status_log']):0;
        $verifier=!empty($deviation_data['DevOwner'])?$deviation_data['DevOwner']['userfullname']:'';
        
        if(!$this->Authorization->can($deviation_data, 'verifydev')){
            $this->Flash->error(__('Only Owner have permission to verify deviation.'));
            return $this->redirect(['action' => 'index']);
        }
        if ($this->request->is(['patch', 'post', 'put'])) {
            $requestData=$this->request->getData();//debug($requestData);die;
            $dev_status_log=$requestData['dev_status_log'];
            
            if(trim($requestData['removePlans'])!=""){
                $this->loadModel('Dev.DevActionPlans');
                $deleteDeviationPlans=$this->DevActionPlans->find("all",[
                    "conditions"=>[ "id IN ( ".$requestData['removePlans']." )"],
                ])->toArray();
                if(!empty($deleteDeviationPlans) && count($deleteDeviationPlans) > 0){
                    $this->loadComponent('Common');
                    $this->Common->auditTrailForDeleteAll(array_column($deleteDeviationPlans,'id'),'dev_action_plans','deviation',$id);
                    unset($requestData['removePlans']);
                }
            }
            
            if(isset($requestData['submitVerify'])){
                $requestData['dev_status_master_id']=3;
            }
            $deviation_data = $this->Deviation->patchEntity($deviation_data, $requestData);
            //debug($deviation_data);die;
            if ($result=$this->Deviation->save($deviation_data)) {
                $dev_id=$id;
                $dev_action_plans=$result['dev_action_plans'];
                if(isset($requestData['submitVerify'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                    }
                }
                if(isset($dev_status_log['id']) && $dev_status_log['id'] != ''){
                    $status_log = $this->Deviation->DevStatusLog->get($dev_status_log['id']);
                }else{
                    $status_log = $this->Deviation->DevStatusLog->newEmptyEntity();
                }
                $status_log=$this->Deviation->DevStatusLog->patchEntity($status_log,$dev_status_log);
                $this->Deviation->DevStatusLog->save($status_log);              
                $dev_status=$this->loadModel('DevStatusMaster');
                $nextstatus= $dev_status->find()->select(['status'])->where(['id =' => $nextStatusId])->first();
                $nextstatus=$nextstatus->status;
                
                if(isset($requestData['submitVerify'])){
                    $title="Deviation ".$deviation_data['reference_number']." Sent For ".$nextstatus;
                    if(isset($deviation_data['created_by']) && ($deviation_data['created_by'] != '')){
                        $notification=new SimpleNotification([
                            "notification_inbox_data"=>[
                                "customer_id"=>$customer_id,
                                "created_by"=>$loggedUserId,
                                "user_type"=>"Users",   // accepts User|Groups|Departments
                                "user_reference_id"=>$deviation_data['created_by'], // relavtive id
                                "title"=>$title, // title of notification
                                "comments"=>"Deviation Sent For Approval", // content of notification
                                "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                                "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                                "model_reference_id"=>$dev_id, //   if required
                                "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] // link to redirect to user.
                            ],
                        ]);
                        $notification->send();
                    }
                    
                    $title_verifier='Deviation - '.$deviation_data['reference_number']." Request To ".$nextstatus;
                    if(isset($dev_status_log['next_action_by']) && ($dev_status_log['next_action_by'] != '')){
                        $notification_v=new SimpleNotification([
                            "notification_inbox_data"=>[
                                "customer_id"=>$customer_id,
                                "created_by"=>$loggedUserId,
                                "user_type"=>"Users",   // accepts User|Groups|Departments
                                "user_reference_id"=>$dev_status_log['next_action_by'], // relavtive id
                                "title"=>$title_verifier, // title of notification
                                "comments"=>"Deviation QA Approval Request", // content of notification
                                "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                                "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                                "model_reference_id"=>$dev_id, //   if required
                                "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"approveqa", $dev_id] // link to redirect to user.
                            ],
                        ]);
                        $notification_v->send();
                    }
                    
                    foreach ($dev_action_plans as $actionplan)
                    {
                        $title_action='Deviation - '.$deviation_data['reference_number']." Added Task For You.";
                        if(isset($dev_status_log['next_action_by']) && ($dev_status_log['next_action_by'] != '')){
                            $notification_v=new SimpleNotification([
                                "notification_inbox_data"=>[
                                    "customer_id"=>$actionplan->assigned_to,
                                    "created_by"=>$loggedUserId,
                                    "user_type"=>"Users",   // accepts User|Groups|Departments
                                    "user_reference_id"=>$dev_status_log['next_action_by'], // relavtive id
                                    "title"=>$title_action, // title of notification
                                    "comments"=>"Deviation QA Approval Request", // content of notification
                                    "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                                    "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                                    "model_reference_id"=>$dev_id, //   if required
                                    "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"devtasks", $isplanned] // link to redirect to user.
                                ],
                            ]);
                            $notification_v->send();
                        }
                    }
                }
                
                $this->Flash->success(__('The deviation has been saved.'));
                
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>3])->last();
        $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>2])->last();
        
        $this->set(compact('currentLog','preLog', 'currentStatusId', 'nextStatusId', 'prevStatusId'));
        $this->getDeviationData($id,true);
        $this->viewBuilder()->setTemplate("verifydev");
        $this->set('DateTimeFormat',$this->DateTimeFormat); //for passing variable to template
    }
    public function approveqa1($id = null, $currentStatusId=null, $nextStatusId=null, $prevStatusId=null)
    {
        $id=($id==null)?null:decryptVal($id);
        $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
        $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
        $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
        
        $deviation_data = $this->Deviation->get($id, [
            'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                'DevStatusLog'=>[
                    'DevNextActionBy'=>['fields'=>['userfullname']],
                ],
                'DevOwner'=>['fields'=>['userfullname']],
            ],
        ]);
        $isplanned='';
        if($deviation_data['isplanned']==2){
            $isplanned="un";
        }
        $this->getdeviationApplicableSteps($id);
        $customer_id=$deviation_data['customer_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        $lastrecord=!empty($deviation_data['dev_status_log'])?count($deviation_data['dev_status_log']):0;
        $verifier=($lastrecord>0)?!empty($deviation_data['dev_status_log'][$lastrecord-1]['DevNextActionBy'])?$deviation_data['dev_status_log'][$lastrecord-1]['DevNextActionBy']['userfullname']:'':'';
        $dev_owner=!empty($deviation_data['DevOwner'])?$deviation_data['DevOwner']['userfullname']:'';
        $deviation_data->previousStepId = $prevStatusId ;
        if(!$this->Authorization->can($deviation_data, 'approveqa1')){
            if($deviation_data['dev_status_master_id'] > $currentStatusId){
                $this->Flash->error(__('Deviation is already approved.'));
            }else{
                $this->Flash->error(__('Only '.$verifier.' (Owner) have permission to approve deviation.'));
            }
            return $this->redirect(['action' => 'index']);
        }
        if ($this->request->is(['patch', 'post', 'put'])) {
            $requestData=$this->request->getData();//debug($requestData);die;
            $dev_status_log=$requestData['dev_status_log'];
            
            if(isset($requestData['submitApprove'])){
                $requestData['dev_status_master_id']=$currentStatusId;
            }
            $deviation_data = $this->Deviation->patchEntity($deviation_data, $requestData);
            //debug($deviation_data);die;
            if ($result=$this->Deviation->save($deviation_data)) {
                $dev_id=$id;
                $status=$dev_status_log['action_taken'];
                if(isset($requestData['submitApprove'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                        
                        $status_log = $this->Deviation->DevStatusLog->newEmptyEntity();
                        $status_log=$this->Deviation->DevStatusLog->patchEntity($status_log,$dev_status_log);
                        $this->Deviation->DevStatusLog->save($status_log);
                    }
                }
                
                $dev_status=$this->loadModel('DevStatusMaster');
                $nextstatus= $dev_status->find()->select(['status'])->where(['id =' => $nextStatusId])->first();
                $nextstatus=$nextstatus->status;
                
                $title="Deviation ".$deviation_data['reference_number']." Sent For ".$nextstatus;
                if(isset($deviation_data['reported_by']) && ($deviation_data['reported_by'] != '')){
                    $notification=new SimpleNotification([
                        "notification_inbox_data"=>[
                            "customer_id"=>$customer_id,
                            "created_by"=>$loggedUserId,
                            "user_type"=>"Users",   // accepts User|Groups|Departments
                            "user_reference_id"=>$deviation_data['reported_by'], // relavtive id
                            "title"=>$title, // title of notification
                            "comments"=>"Deviation Status - ".$status, // content of notification
                            "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                            "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                            "model_reference_id"=>$dev_id, //   if required
                            "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] // link to redirect to user.
                        ],
                    ]);
                    $notification->send();
                }
                if(isset($deviation_data['dev_owner_id']) && ($deviation_data['dev_owner_id'] != '')){
                    $notification=new SimpleNotification([
                        "notification_inbox_data"=>[
                            "customer_id"=>$customer_id,
                            "created_by"=>$loggedUserId,
                            "user_type"=>"Users",   // accepts User|Groups|Departments
                            "user_reference_id"=>$deviation_data['dev_owner_id'], // relavtive id
                            "title"=>$title, // title of notification
                            "comments"=>"Deviation Status - ".$status, // content of notification
                            "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                            "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                            "model_reference_id"=>$dev_id, //   if required
                            "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] // link to redirect to user.
                        ],
                    ]);
                    $notification->send();
                }
                if(isset($requestData['submitReject'])){
                    $this->loadModel('DevStatusLog');
                    $this->loadModel('DevStatusMaster');
                    $lastuser= $this->DevStatusLog->find()->select(['action_by'])->where(['dev_id =' => $dev_id,"dev_status_master_id"=>$prevStatusId])->first();
                    $lastuserid=$lastuser->action_by;
                    
                    $laststep= $this->DevStatusMaster->find()->select(['form_name','controller_name'])->where(["id"=>$prevStatusId])->first();
                    $controller=$laststep->controller_name;
                    $action=$laststep->form_name;
                    $rejectComment =$dev_status_log['next_action_by_comments'];
                    $title = "Your Request of Deviation ".$deviation_data['reference_number']." is Rejected by ".$this->request->getSession()->read('Auth.first_name')." ".$this->request->getSession()->read('Auth.last_name');
                    
                    $notification=new SimpleNotification([
                        "notification_inbox_data"=>[
                            "customer_id"=>$lastuserid,
                            "created_by"=>$loggedUserId,
                            "user_type"=>"Users",   // accepts User|Groups|Departments
                            "user_reference_id"=>$deviation_data['reported_by'], // relavtive id
                            "title"=>$title, // title of notification
                            "comments"=>"Deviation Step Rejected ", // content of notification
                            "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                            "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                            "model_reference_id"=>$dev_id, //   if required
                            "action_link"=>["plugin"=>"Dev", "controller"=>$controller,"action"=>$action, $dev_id] // link to redirect to user.
                        ],
                    ]);
                    $notification->send();
                   // return $this->redirect(['action' => 'rejectstatus',$dev_id,$prevStatusId,$currentStatusId]);
                    $this->rejectstatus($dev_id,$prevStatusId,$currentStatusId,$rejectComment);
                }
                $this->Flash->success(__('The deviation has been saved.'));
                
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$currentStatusId,'DevStatusLog.action_taken !='=>'Reject',])->last();
        $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$prevStatusId])->last();
        
        $this->set(compact('currentLog','preLog', 'currentStatusId', 'nextStatusId', 'prevStatusId'));
        $this->getDeviationData($id,true);
        $this->viewBuilder()->setTemplate("approveqa1");
    }
    public function approveqa2($id = null, $currentStatusId=null, $nextStatusId=null, $prevStatusId=null)
    {
        $id=($id==null)?null:decryptVal($id);
        $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
        $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
        $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
        
        $deviation_data = $this->Deviation->get($id, [
            'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                'DevStatusLog'=>['DevNextActionBy'=>['fields'=>['userfullname']]],
                'DevOwner'=>['fields'=>['userfullname']],
            ],
        ]);
        $isplanned='';
        if($deviation_data['isplanned']==2){
            $isplanned="un";
        }
        $this->getdeviationApplicableSteps($id);
        $customer_id=$deviation_data['customer_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        $lastrecord=!empty($deviation_data['dev_status_log'])?count($deviation_data['dev_status_log']):0;
        $verifier=($lastrecord>0)?!empty($deviation_data['dev_status_log'][$lastrecord-1]['DevNextActionBy'])?$deviation_data['dev_status_log'][$lastrecord-1]['DevNextActionBy']['userfullname']:'':'';
        $dev_owner=!empty($deviation_data['DevOwner'])?$deviation_data['DevOwner']['userfullname']:'';
        if(!$this->Authorization->can($deviation_data, 'approveqa2')){
            if($deviation_data['dev_status_master_id'] >= $currentStatusId){
                $this->Flash->error(__('Deviation is already approved.'));
            }else{
                $this->Flash->error(__('Only '.$verifier.' (Owner) have permission to approve deviation.'));
            }
            return $this->redirect(['action' => 'index']);
        }
        if ($this->request->is(['patch', 'post', 'put'])) {
            $requestData=$this->request->getData();//debug($requestData);die;
            $dev_status_log=$requestData['dev_status_log'];
            
            if(isset($requestData['submitApprove'])){
                $requestData['dev_status_master_id']=$currentStatusId;
            }
            $deviation_data = $this->Deviation->patchEntity($deviation_data, $requestData);
           
            if ($result=$this->Deviation->save($deviation_data)) {
                $dev_id=$id;
                $status=$dev_status_log['action_taken'];
                if(isset($requestData['submitApprove'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                        
                        $status_log = $this->Deviation->DevStatusLog->newEmptyEntity();
                        $status_log=$this->Deviation->DevStatusLog->patchEntity($status_log,$dev_status_log);
                        $this->Deviation->DevStatusLog->save($status_log);
                    }
                }
                
                $dev_status=$this->loadModel('DevStatusMaster');
                $nextstatus= $dev_status->find()->select(['status'])->where(['id =' => $nextStatusId])->first();
                $nextstatus=$nextstatus->status;
                                
                $title="Deviation ".$deviation_data['reference_number']." Send To ".$nextstatus;                
                if(isset($deviation_data['reported_by']) && ($deviation_data['reported_by'] != '')){
                    $notification=new SimpleNotification([
                        "notification_inbox_data"=>[
                            "customer_id"=>$customer_id,
                            "created_by"=>$loggedUserId,
                            "user_type"=>"Users",   // accepts User|Groups|Departments
                            "user_reference_id"=>$deviation_data['reported_by'], // relavtive id
                            "title"=>$title, // title of notification
                            "comments"=>"Deviation Status - ".$status, // content of notification
                            "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                            "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                            "model_reference_id"=>$dev_id, //   if required
                            "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] // link to redirect to user.
                        ],
                    ]);
                    $notification->send();
                }
                if(isset($deviation_data['dev_owner_id']) && ($deviation_data['dev_owner_id'] != '')){
                    $notification=new SimpleNotification([
                        "notification_inbox_data"=>[
                            "customer_id"=>$customer_id,
                            "created_by"=>$loggedUserId,
                            "user_type"=>"Users",   // accepts User|Groups|Departments
                            "user_reference_id"=>$deviation_data['dev_owner_id'], // relavtive id
                            "title"=>$title, // title of notification
                            "comments"=>"Deviation Status - ".$status, // content of notification
                            "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                            "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                            "model_reference_id"=>$dev_id, //   if required
                            "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] // link to redirect to user.
                        ],
                    ]);
                    $notification->send();
                }
                if(isset($requestData['submitReject'])){
                    $this->loadModel('DevStatusLog');
                    $this->loadModel('DevStatusMaster');
                    $lastuser= $this->DevStatusLog->find()->select(['action_by'])->where(['dev_id =' => $dev_id,"dev_status_master_id"=>$prevStatusId])->first();
                    $lastuserid=$lastuser->action_by;
                    
                    $laststep= $this->DevStatusMaster->find()->select(['form_name','controller_name'])->where(["id"=>$prevStatusId])->first();
                    $controller=$laststep->controller_name;
                    $action=$laststep->form_name;
                    
                    $title = "Your Request of Deviation ".$deviation_data['reference_number']." is Rejected by ".$this->request->getSession()->read('Auth.first_name')." ".$this->request->getSession()->read('Auth.last_name');
                    
                    $notification=new SimpleNotification([
                        "notification_inbox_data"=>[
                            "customer_id"=>$lastuserid,
                            "created_by"=>$loggedUserId,
                            "user_type"=>"Users",   // accepts User|Groups|Departments
                            "user_reference_id"=>$deviation_data['reported_by'], // relavtive id
                            "title"=>$title, // title of notification
                            "comments"=>"Deviation Step Rejected ", // content of notification
                            "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                            "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                            "model_reference_id"=>$dev_id, //   if required
                            "action_link"=>["plugin"=>"Dev", "controller"=>$controller,"action"=>$action, $dev_id] // link to redirect to user.
                        ],
                    ]);
                    $notification->send();
                    return $this->redirect(['action' => 'rejectstatus',$dev_id,$prevStatusId,$currentStatusId]);
                    //$this->rejectstatus($dev_id,$prevStatusId,$currentStatusId);
                }
                $this->Flash->success(__('The deviation has been saved.'));
                
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$currentStatusId,'DevStatusLog.action_taken !='=>'Reject',])->last();
        $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$prevStatusId])->last();
        
        $this->set(compact('currentLog','preLog', 'currentStatusId', 'nextStatusId', 'prevStatusId'));
        $this->getDeviationData($id,true);
        $this->viewBuilder()->setTemplate("approveqa2");
    }
    public function approveqa($id = null, $currentStatusId=null, $nextStatusId=null, $prevStatusId=null)
    {
        $id=($id==null)?null:decryptVal($id);
        $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
        $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
        $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
        $plugin=$this->request->getParam('plugin');
        $controller=$this->request->getParam('controller');
        $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
        $loggedUserDeptId=$this->request->getSession()->read('Auth')['departments_id'];
        $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
        $statusData=array("current_id"=>$currentStatusId,"next_id"=>$nextStatusId,"prev_id"=>$prevStatusId);
        $deviation_data = $this->Deviation->get($id, [
            'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                'DevStatusLog'=>['DevNextActionBy'=>['fields'=>['userfullname']]],
                'DevOwner'=>['fields'=>['userfullname']],
            ],
        ]);
       
        $isplanned='';
        if($deviation_data['isplanned']==2){
            $isplanned="un";
        }
        $this->getdeviationApplicableSteps($id);
        $customer_id=$deviation_data['customer_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        $lastrecord=!empty($deviation_data['dev_status_log'])?count($deviation_data['dev_status_log']):0;
        $verifier=($lastrecord>0)?!empty($deviation_data['dev_status_log'][$lastrecord-1]['DevNextActionBy'])?$deviation_data['dev_status_log'][$lastrecord-1]['DevNextActionBy']['userfullname']:'':'';
        $dev_owner=!empty($deviation_data['DevOwner'])?$deviation_data['DevOwner']['userfullname']:'';
        $deviation_data->previousStepId = $prevStatusId ;
        $deviation_data->nextStepId = $nextStatusId ;
        if(!$this->Authorization->can($deviation_data, 'approveqa')){
            if($deviation_data['dev_status_master_id'] >= $currentStatusId){
                $this->Flash->error(__('Deviation is already approved.'));
            }else{
                $this->Flash->error(__('Only '.$verifier.' (Owner) have permission to approve deviation.'));
            }
            return $this->redirect(['action' => 'index']);
        }
        
        //added by shrirang
        $this->loadComponent('WiseWorks');
        $allSteps=$this->WiseWorks->getAllSteps();
        $status_step = $this->WiseWorks->getSingleStep($currentStatusId,$allSteps);//debug($deviation_data);
     
        $this->getTransPassData($status_step);
        
        if ($this->request->is(['patch', 'post', 'put'])) {
            $requestData=$this->request->getData();
            $duplicate_deviation= isset($requestData['duplicate_deviation'])?$requestData['duplicate_deviation']:'';
           
            $saveDatas = array();
            if($duplicate_deviation != ''){
                $duplicate_deviation=json_decode($duplicate_deviation);
                $this->loadModel('DeviationDuplicate');
                foreach($duplicate_deviation as $k=>$row){
                    $saveDatas['deviation_id'] = $id;
                    $saveDatas['duplicate_devation_id'] = $row;
                    $saveDatas['comment'] = $requestData['comment'];
                    $duplocateData = $this->DeviationDuplicate->newEmptyEntity();
                    $duplocateData=$this->DeviationDuplicate->patchEntity($duplocateData,$saveDatas);
                    $res = $this->DeviationDuplicate->save($duplocateData);
                }
               
                
                
              
            }
            
           
             $dev_status_log=$requestData['dev_status_log'];
            if(isset($requestData['submitApprove'])){
                $requestData['dev_status_master_id']=$currentStatusId;
            }
            
            $this->loadComponent('Common');
            $this->Common->close_notification($plugin,'Deviation',$id);
            $deviation_data = $this->Deviation->patchEntity($deviation_data, $requestData);
          
            if ($result=$this->Deviation->save($deviation_data)) {
                $dev_id=$id;
                $status=$dev_status_log['action_taken'];
                if(isset($requestData['submitApprove'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                        
                        $status_log = $this->Deviation->DevStatusLog->newEmptyEntity();
                        $status_log=$this->Deviation->DevStatusLog->patchEntity($status_log,$dev_status_log);
                        $this->Deviation->DevStatusLog->save($status_log);
                        $this->loadModel('DevActionPlans');
                        $this->DevActionPlans->updateAll(array('status' => 'Open'), array('dev_id' => $dev_id));
                    }
                }
                
                $dev_status=$this->loadModel('DevStatusMaster');
                $nextstatus= $dev_status->find()->select(['status'])->where(['id =' => $nextStatusId])->first();
                $nextstatus=$nextstatus->status;
                
                if(isset($requestData['submitApprove'])){

                    //added by shrirang
                    $prevStatusId =$currentStatusId;
                    $current_status_id = $nextStatusId;
                    $nextStatussId = $this->WiseWorks->getNextStep($nextStatusId,$dev_id);
                    $title = "Deviation ".$result->reference_number." QA Approval done";
                    $notificationData = [
                        'selected_users' => $result->created_by,
                        'reference_number' =>$result->reference_number,
                        'title' =>$title,
                        'category'=>$result->classification,
                        'against'=>$result->against,
                        'target_date'=>$result->target_date,
                        'customer_id'=>$customer_id,
                        'created_by'=>$loggedUserId,
                        'notification_identifier'=>'qaApproval_done',
                        'id' =>$dev_id,
                        'current_status_id'=>$current_status_id,
                        'nextStatusId'=>$nextStatussId,
                        'prevStatusId'=>$prevStatusId
                    ];
                    
                    $this->loadComponent('CommonMailSending');
                    $this->CommonMailSending->selected_email_details($customer_id,$customer_location_id,$loggedUserId,$notificationData);
                    $this->loadComponent('QmsNotification');
                    $this->QmsNotification->selected_notificaion_details($plugin,'DevInvestigation',$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    //Ended by here by shrirang
                }
               if(isset($requestData['submitReject'])){
                    $this->loadModel('DevStatusLog');
                    $this->loadModel('DevStatusMaster');
                    $lastuser= $this->DevStatusLog->find()->select(['action_by'])->where(['dev_id =' => $dev_id,"dev_status_master_id"=>$prevStatusId])->first();
                    $lastuserid=$lastuser->action_by;
                    $rejectComment =$dev_status_log['next_action_by_comments'];
                    $laststep= $this->DevStatusMaster->find()->select(['form_name','controller_name'])->where(["id"=>$prevStatusId])->first();
                    $controller=$laststep->controller_name;
                    $action=$laststep->form_name;
                  
                    //added by shrirang
                    $nextStatussId =$currentStatusId;
                    $current_status_id = $this->WiseWorks->getPreviousStep($nextStatussId,$dev_id);
                    $prevsStatusId = $this->WiseWorks->getPreviousStep($current_status_id,$dev_id);
  
                    $title = "Deviation ".$result->reference_number." QA Approval rejected";
                    $notificationData = [
                        'selected_users' => $lastuserid,
                        'reference_number' =>$result->reference_number,
                        'title' =>$title,
                        'category'=>$result->classification,
                        'against'=>$result->against,
                        'target_date'=>$result->target_date,
                        'customer_id'=>$customer_id,
                        'created_by'=>$loggedUserId,
                        'notification_identifier'=>'qaApproval_rejected',
                        'id' =>$dev_id,
                        'current_status_id'=>$current_status_id,
                        'nextStatusId'=>$nextStatussId,
                        'prevStatusId'=>$prevsStatusId
                    ];
                    
                    $this->loadComponent('CommonMailSending');
                    $this->CommonMailSending->selected_email_details($customer_id,$customer_location_id,$loggedUserId,$notificationData);
                    $this->loadComponent('QmsNotification');
                    $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    //Ended by here by shrirang
                    //return $this->redirect(['action' => 'rejectstatus',$dev_id,$prevStatusId,$currentStatusId]);
                    $this->rejectstatus($dev_id,$prevStatusId,$currentStatusId,$rejectComment);
                }
                $this->Flash->success(__('The deviation has been saved.'));
                
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        $this->loadComponent('Common');
        $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$currentStatusId,'DevStatusLog.action_taken !='=>'Reject',])->last();
        $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$prevStatusId])->last();
        $userCondition=array("Users.customer_id"=>$customer_id,"Users.active"=>1);//debug($userCondition);die;
        $users=$this->Common->getUsersArray($userCondition);
        $userCondition=array("plugin"=>$plugin,"controller"=>$controller,"element_name"=>'department_head',"is_auth"=>1,'RolewisePermissions.can_approve'=>1);
        //debug($userCondition);die;
        $deptHeadUsers=$this->Common->getUserDeptHead($userCondition,$loggedUserDeptId);
        $this->set(compact('currentLog','preLog', 'currentStatusId', 'nextStatusId', 'prevStatusId','statusData','deptHeadUsers','dev_owner'));
        $this->getDeviationData($id,true);
        $this->viewBuilder()->setTemplate("approveqa");
        $this->set('DateTimeFormat',$this->DateTimeFormat); //for passing variable to template
    }
    public function rejectstatus($dev_id, $reject_status_id, $dev_reject_master_id=null,$rejectComment=null,$current_log_id=null)
    {
        $this->Authorization->skipAuthorization();
        $this->loadModel("Dev.DevStatusLog");
//         debug($dev_reject_master_id);
//         debug($reject_status_id);
        $condition=array(
            "dev_id"=>$dev_id,
            "dev_status_master_id"=>$reject_status_id
        );
        
        $this->DevStatusLog->updateAll(["step_complete"=>0,"action_taken"=>"Reject"],["dev_status_master_id >"=>$reject_status_id]);
        
        $DevStatusLogDt=$this->DevStatusLog->find("all",["conditions"=>$condition])->toArray();
        $updateData=array(
            "step_complete"=>0,
            "comments"=>!empty($rejectComment)?$rejectComment:"Rejected by ".$this->request->getSession()->read('Auth.first_name')." ".$this->request->getSession()->read('Auth.last_name')
        );
        
        foreach($DevStatusLogDt as $DevStatusLogDt1){
            $DevStatusLogData=$this->DevStatusLog->patchEntity($DevStatusLogDt1, $updateData);
            $this->DevStatusLog->save($DevStatusLogData);
        }
        $deviation = $this->Deviation->get($dev_id);
        $updateData=array(
            "dev_status_master_id"=>$reject_status_id,
            "id"=>$dev_id,
        );
        $lastuser= $this->DevStatusLog->find('all')->where(['dev_id =' => $dev_id,"dev_status_master_id"=>$reject_status_id])->last();
        $lastuserid=$lastuser->action_by;
        $this->loadComponent('WiseWorks');
        $prevStatusId = $this->WiseWorks->getPreviousStep($dev_reject_master_id,$dev_id);
        
        if($prevStatusId == NUll){
            $prevStatusId = $reject_status_id;
        }
        
     //   if($reject_status_id !=1){
        $lastnextactionby= $this->DevStatusLog->find('all')->where(['dev_id =' => $dev_id,"dev_status_master_id"=>$prevStatusId])->last();
       // }else{
       //     $lastnextactionby= $this->DevStatusLog->find('all')->where(['dev_id =' => $dev_id,"dev_status_master_id"=>$reject_status_id])->last();
            
      //  }
        //debug($lastnextactionby);die;
        if($dev_reject_master_id!=null){
            $rejectStatusLogDt=array(
                "dev_id"=>$dev_id,
                "dev_status_master_id"=>$dev_reject_master_id,
                "action_taken"=>"Reject",
                "step_complete"=>1,
                "next_action_by"=>$lastuserid,
                "actual_start_date"=>date("Y-m-d"),
                "actual_end_date"=>date("Y-m-d"),
                "action_by"=>$this->request->getSession()->read('Auth.id'),
                "next_action_by_comments"=>!empty($rejectComment)?$rejectComment:"Rejected by ".$this->request->getSession()->read('Auth.first_name')." ".$this->request->getSession()->read('Auth.last_name')
            );
            if (isset($current_log_id) && $current_log_id != null) {
                $RejectDevStatusLogDt = $this->Deviation->DevStatusLog->get($current_log_id);
            }
            else {
                $RejectDevStatusLogDt = $this->DevStatusLog->newEmptyEntity();
            }
            
            $RejectDevStatusLogDt = $this->DevStatusLog->patchEntity($RejectDevStatusLogDt, $rejectStatusLogDt);
            $this->DevStatusLog->save($RejectDevStatusLogDt);
            
        //     $rejectStatusLogNewDts=array(
        //         "dev_id"=>$dev_id,
        //         "dev_status_master_id"=>$reject_status_id,
        //         "action_taken"=>"Submit",
        //         "step_complete"=>0,
        //         "actual_start_date"=>date("Y-m-d"),
        //         "actual_end_date"=>date("Y-m-d"),
        //         "action_by"=>$this->request->getSession()->read('Auth.id'),
        //         "next_action_by"=>$lastnextactionby['action_by'],
        //     );
        //     $rejectStatusLogNewDt = $this->DevStatusLog->newEmptyEntity();
        //     $rejectStatusLogNewDt = $this->DevStatusLog->patchEntity($rejectStatusLogNewDt, $rejectStatusLogNewDts);
        //  // debug($rejectStatusLogNewDt);die;
        //     $this->DevStatusLog->save($rejectStatusLogNewDt);
        }
        if($reject_status_id==2){
            $updateData['dev_status_master_id']=1;
        }
        $deviation = $this->Deviation->patchEntity($deviation, $updateData);
        $this->Deviation->save($deviation, $updateData);
        $this->Flash->success(__('The {0} has been Rejected', 'Deviation'));
        
        $isplanned='';
        if($deviation['isplanned']==2){
            $isplanned="un";
            return $this->redirect(['action' => 'index']);
        }
        return $this->redirect(['action' => 'index']);
    }
    public function devcontain($id = null, $currentStatusId=null, $nextStatusId=null, $prevStatusId=null)
    {
        $id=($id==null)?null:decryptVal($id);
        $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
        $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
        $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
        $deviation_data = $this->Deviation->get($id, [
            'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                'DevStatusLog'=>['DevNextActionBy'=>['fields'=>['userfullname']]],
                "DevOwner"=>['fields'=>['userfullname']],
                "DevAccess","DevContainment",
            ],
        ]);
        $isplanned='';
        if($deviation_data['isplanned']==2){
            $isplanned="un";
        }
        $customer_id=$deviation_data['customer_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        //$createdBy=!empty($deviation_data['CreatedByUser'])?$deviation_data['CreatedByUser']['userfullname']:'';
        //$lastrecord=!empty($deviation_data['dev_status_log'])?count($deviation_data['dev_status_log']):0;
        $verifier=!empty($deviation_data['DevOwner'])?$deviation_data['DevOwner']['userfullname']:'';
        
        if(!$this->Authorization->can($deviation_data, 'devcontain')){
            $this->Flash->error(__('Only '.$verifier.' (Owner) have permission to define containment detail.'));
            return $this->redirect(['action' => 'index']);
        }
        if ($this->request->is(['patch', 'post', 'put'])) {
            $requestData=$this->request->getData();//debug($requestData);die;
            $dev_status_log=$requestData['dev_status_log'];
            if(isset($requestData['submitVerify'])){
                $requestData['dev_status_master_id']=$currentStatusId;
            }
            $deviation_data = $this->Deviation->patchEntity($deviation_data, $requestData);
            //debug($deviation_data);die;
            if ($result=$this->Deviation->save($deviation_data)) {
                $dev_id=$id;
                
                if(isset($requestData['submitVerify'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                    }
                }
                if(isset($dev_status_log['id']) && $dev_status_log['id'] != ''){
                    $status_log = $this->Deviation->DevStatusLog->get($dev_status_log['id']);
                }else{
                    $status_log = $this->Deviation->DevStatusLog->newEmptyEntity();
                }
                $status_log=$this->Deviation->DevStatusLog->patchEntity($status_log,$dev_status_log);
                $this->Deviation->DevStatusLog->save($status_log);
                
                if(isset($requestData['submitVerify'])){
                    $title="Deviation ".$deviation_data['reference_number']." containment is done and sent For Investigation";
                    if(isset($deviation_data['created_by']) && ($deviation_data['created_by'] != '')){
                        $notification=new SimpleNotification([
                            "notification_inbox_data"=>[
                                "customer_id"=>$customer_id,
                                "created_by"=>$loggedUserId,
                                "user_type"=>"Users",   // accepts User|Groups|Departments
                                "user_reference_id"=>$deviation_data['created_by'], // relavtive id
                                "title"=>$title, // title of notification
                                "comments"=>"Deviation containment is done and sent For Investigation", // content of notification
                                "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                                "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                                "model_reference_id"=>$dev_id, //   if required
                                "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] // link to redirect to user.
                            ],
                        ]);
                        $notification->send();
                    }
                    if(!empty($deviation_data['dev_access'])){
                        foreach($deviation_data['dev_access'] as $forUserid){
                            if($forUserid->access_for == 'dbmApproval'){
                                $notification_v=new SimpleNotification([
                                    "notification_inbox_data"=>[
                                        "customer_id"=>$customer_id,
                                        "created_by"=>$loggedUserId,
                                        "user_type"=>"Users",   // accepts User|Groups|Departments
                                        "user_reference_id"=>$forUserid->approver_id, // relavtive id
                                        "title"=>$title, // title of notification
                                        "comments"=>"Deviation containment is done and sent For Investigation", // content of notification
                                        "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                                        "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                                        "model_reference_id"=>$dev_id, //   if required
                                        "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] // link to redirect to user.
                                    ],
                                ]);
                                $notification_v->send();
                            }
                        }
                    }
                    if(isset($requestData['performed_by']) && ($requestData['performed_by'] != '')){
                        $notification=new SimpleNotification([
                            "notification_inbox_data"=>[
                                "customer_id"=>$customer_id,
                                "created_by"=>$loggedUserId,
                                "user_type"=>"Users",   // accepts User|Groups|Departments
                                "user_reference_id"=>$requestData['performed_by'], // relavtive id
                                "title"=>$title, // title of notification
                                "comments"=>"Deviation containment is done and sent For Investigation", // content of notification
                                "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                                "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                                "model_reference_id"=>$dev_id, //   if required
                                "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] // link to redirect to user.
                            ],
                        ]);
                        $notification->send();
                    }
                    if(isset($requestData['approved_by']) && ($requestData['approved_by'] != '')){
                        $notification=new SimpleNotification([
                            "notification_inbox_data"=>[
                                "customer_id"=>$customer_id,
                                "created_by"=>$loggedUserId,
                                "user_type"=>"Users",   // accepts User|Groups|Departments
                                "user_reference_id"=>$requestData['approved_by'], // relavtive id
                                "title"=>$title, // title of notification
                                "comments"=>"Deviation containment is done and sent For Investigation", // content of notification
                                "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                                "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                                "model_reference_id"=>$dev_id, //   if required
                                "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] // link to redirect to user.
                            ],
                        ]);
                        $notification->send();
                    }
                }
                
                $this->Flash->success(__('The deviation has been saved.'));
                
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$currentStatusId])->last();
        $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$prevStatusId])->last();
        
        $this->set(compact('currentLog','preLog', 'currentStatusId', 'nextStatusId', 'prevStatusId'));
        $this->getDeviationData($id,true);
        $this->viewBuilder()->setTemplate("devcontain");
    }
    public function devtasks($isPlanned=null)
    {
        $deviationX = $this->Deviation->newEmptyEntity();
        if(!$this->Authorization->can($deviationX,'index')){
            $this->Flash->error(__('You are not allowed to access Deviation Module'));
            return $this->redirect(["plugin"=>false,"controller"=>"pages",'action' => 'home']);
        }
        $tasktypevalue=$this->request->getQuery('tasktype');
        $cust_id=$this->request->getSession()->read('Auth')['customer_id'];
        $user_id=$this->request->getSession()->read('Auth')['id'];
        $this->loadModel('Dev.DevActionPlans');
        $query=$this->request->getQuery("table_search");
        $isplannedStaus=1;
        if($isPlanned=="un"){
            $isplannedStaus=2;
        }
        $conditions=[];
        if($tasktypevalue == 'mycompletedtask'){
            $conditions=["Deviation.customer_id"=>$cust_id,"DevActionPlans.assigned_to"=>$user_id,"(DevActionPlans.status IN('Close','Complete','Pending'))","Deviation.isplanned=$isplannedStaus"];
        }elseif($tasktypevalue == 'allpendingtask'){
            $conditions=["Deviation.customer_id"=>$cust_id,"(DevActionPlans.status NOT IN('Close','Complete','Pending') OR DevActionPlans.status IS NULL)","(DevActionPlans.action_for IN('Implement') OR DevActionPlans.action_for IN('ImplementPlan'))","Deviation.isplanned=$isplannedStaus"];
        }elseif($tasktypevalue == 'allcompletedtask'){
            $conditions=["Deviation.customer_id"=>$cust_id,"DevActionPlans.status IN('Close','Complete','Pending')","Deviation.isplanned=$isplannedStaus"];
        }else{
            $conditions=array(['AND'=>["Deviation.customer_id"=>$cust_id,"DevActionPlans.assigned_to"=>$user_id,"(DevActionPlans.status NOT IN('Close','Pending') OR DevActionPlans.status IS NULL)","(DevActionPlans.action_for IN('Implement') OR DevActionPlans.action_for IN('ImplementPlan'))"]]);
        }
        
        if($query!=null && trim($query," ")!=""){
            $conditions["or"][]="Deviation.reference_number like '%$query%'";
            $conditions["or"][]="DevActionPlans.title like '%$query%'";
            $conditions["or"][]="DevActionPlans.description like '%$query%'";
        }
        $this->paginate = [
            "fields"=>["DevActionPlans.status","DevActionPlans.action_for","DevActionPlans.id","DevActionPlans.title","DevActionPlans.checklist_id",
                "DevActionPlans.action_description","DevActionPlans.planned_start_date","DevActionPlans.planned_end_date","DevActionPlans.status",
                "Deviation.reference_number","Deviation.id","AssignedTo.userfullname","DevActionPlans.actual_start_date","DevActionPlans.actual_end_date",
                "Checklist.doc_no","Checklist.title"],
            "conditions"=>$conditions,
            "contain"=>[
                "Deviation","DevActionPlanAttachment","Checklist",
                "AssignedTo"=>["fields"=>["userfullname"]]
            ],
            "order"=>["id"=>"desc"]
        ];
        $devActionPlans = $this->paginate($this->DevActionPlans);
        $this->set(compact('tasktypevalue','query'));
        $this->set('devActionPlans', $devActionPlans);
        $this->set('isPlanned', $isPlanned);
    }
    
    public function devactionplan($dev_id=null,$actionplanid=null)
    {
        $dev_id=($dev_id==null)?null:decryptVal($dev_id);
        $actionplanid=($actionplanid==null)?null:decryptVal($actionplanid);
        
        $deviation = $this->Deviation->get($dev_id, [
            'contain'=>[
                'CustomerLocations'=>["fields"=>["name"]],
                "DevActionPlans"=>[
                    "DevActionPlanLog",
                    "conditions"=>["DevActionPlans.id"=>$actionplanid]
                ],
                
                "ProductionLineMaster",
                "ProductsMaster",
                "ProcessMaster",
                //"DocMaster",
            ]
        ]);
        
        if(!$this->Authorization->can($deviation,'devactionplan')){
            $this->Flash->error(__("Task is already completed"));
            return $this->redirect(['action' => 'devtasks']);
        }
        $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
        $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        
        $loggedUserDeptId=$this->request->getSession()->read('Auth')['departments_id'];
        $plugin=$this->request->getParam('plugin');
        $controller=$this->request->getParam('controller');
        $method=$this->request->getParam('action');
        
        $this->loadComponent('Common');
        $isplanned=($deviation['isplanned'] == 2)?'un':'';
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        if ($this->request->is(['patch', 'post', 'put'])){
            $requestedData=$this->request->getData();//debug($requestedData);exit;
            $this->loadModel("Dev.DevActionPlans");
            $devActionPlans = $this->DevActionPlans->get($actionplanid,[]);
            $action_attchments=$requestedData['dev_action_plan_attachment'];
          //  debug($action_attchments);die;
            if(trim($requestedData['removedFiles'])!=""){
                $deleted_dev_action_plan_ids=$requestedData['removedFiles'];
                $this->loadModel("Dev.DevActionPlanAttachment");
                $deletedDevActionPlan=$this->DevActionPlanAttachment->find("all",[
                    "conditions"=>[ "id IN ( ".$deleted_dev_action_plan_ids." )"],
                ])->toArray();
                if(!empty($deletedDevActionPlan) && count($deletedDevActionPlan) > 0){
                    $this->loadComponent('Common');
                    $this->Common->auditTrailForDeleteAll(array_column($deletedDevActionPlan,'id'),'dev_action_plan_attachment','dev_action_plans',$actionplanid);
                    
                    foreach($deletedDevActionPlan as $action_plan){
                        if(trim($action_plan['file_name'])!=""){
                            $filesPathNew="deviation".DS.$action_plan['file_name'];
                            QMSFile::delete($filesPathNew,$customer_id);
                        }
                    }
                }
                
                $this->DevActionPlanAttachment->deleteAll ([ "id IN ( ".$deleted_dev_action_plan_ids." )"]);
            }
            
            foreach($action_attchments as $j=>$action_attchment){
                if(isset($action_attchment["file_name"]) && $action_attchment["file_name"]!=""){
                    $attachments=$action_attchment["file_name"];
                    if($attachments->getError()==0){
                        $filename=date("YmdHis").$attachments->getClientFilename();
                        $tmp_name=$attachments->getStream()->getMetadata('uri');
                        
                        $deviationId=$deviation['id'];
                        $filesPathNew="deviation_task/".$deviationId.DS.$filename;
                        $file_size=filesize($tmp_name);
                        $canUpload=QMSFile::customerFileStorageAvailable($customer_id,$file_size);
                        if($canUpload){
                            if(QMSFile::fileExistscheck($filesPathNew)){
                                QMSFile::moveUploadedFile($tmp_name,"deviation_task/".$deviationId.DS.$filename,$customer_id);
                                $action_attchments[$j]['file_name']=$filename;
                                $action_attchments[$j]['dev_id']=$dev_id;
                                $action_attchments[$j]['dev_action_plan_id']=$actionplanid;
                                $action_attchments[$j]['id']=isset($action_attchment["id"])?$action_attchment["id"]:'';
                            } else{
                                QMSFile::moveUploadedFile($tmp_name,"deviation_task/".$deviationId.DS.$filename,$customer_id);
                                $action_attchments[$j]['file_name']=$filename;
                                $action_attchments[$j]['dev_id']=$dev_id;
                                $action_attchments[$j]['dev_action_plan_id']=$actionplanid;
                                $action_attchments[$j]['id']=isset($action_attchment["id"])?$action_attchment["id"]:'';
                            }
                        }else{
                            unset($action_attchments[$j]);
                            $this->Flash->error(__('unable to upload file.Customer does not have enough storage.'));
                        }
                    }else{
                        unset($action_attchments[$j]);
                    }
                }
            }
            $requestedData['dev_action_plan_attachment']=$action_attchments;
            
            //dev_action_plan_log
            $requestedData['dev_action_plan_log'][0]['dev_action_plan_id'] = $requestedData['id'];
            $requestedData['dev_action_plan_log'][0]['dev_id'] = $dev_id;
            $requestedData['dev_action_plan_log'][0]['status'] = $requestedData['status'];
            $requestedData['dev_action_plan_log'][0]['created_by'] = $loggedUserId;
            $requestedData['dev_action_plan_log'][0]['modified_by'] = $loggedUserId;
            //End dev_action_plan_log
            $this->loadModel("Dev.DevInvestigation");
            $devInvestigation=$this->DevInvestigation->find('all')->where(['dev_id'=>$dev_id])->first();
            $devActionPlans = $this->DevActionPlans->patchEntity($devActionPlans, $requestedData);
            
            if($this->DevActionPlans->save($devActionPlans)){
                //added by shrirang
                $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
                if($requestedData['status'] == 'Complete'){
                    if($devInvestigation->final_classification =='Critical'){
                    $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
                    //  $nextStatussId = $this->WiseWorks->getNextStep($nextStatusId,$dev_id);
                    $title = "Deviation ".$deviation->reference_number." Update Task is done";
                    
                    $notificationData = [
                        //'selected_users' => $loggedUserId,
                        'reference_number' =>$deviation->reference_number,
                        'title' =>$title,
                        'category'=>$deviation->classification,
                        'against'=>$deviation->against,
                        'target_date'=>$deviation->target_date,
                        'created'=>$deviation->created,
                        'customer_id'=>$customer_id,
                        'created_by'=>$loggedUserId,
                        'notification_identifier'=>'criticaltask_done',
                        'id' =>$dev_id,
                        'current_status_id'=>$requestedData['id'],
                        'nextStatusId'=>"",
                        'prevStatusId'=>"",
                    ];
                    
                    
                    $this->loadComponent('CommonMailSending');
                    $this->CommonMailSending->email_details($plugin,$controller,$method,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    $this->loadComponent('QmsNotification');
                    $this->QmsNotification->notificaion_details($plugin,"Deviation",$method,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    }
                    elseif($devInvestigation->final_classification =='Minor' || $devInvestigation->final_classification =='Major'){
                        $title = "Deviation ".$deviation->reference_number." Update Task is done";
                        
                        $notificationData = [
                            'selected_users' => $loggedUserId,
                            'reference_number' =>$deviation->reference_number,
                            'title' =>$title,
                            'category'=>$deviation->classification,
                            'against'=>$deviation->against,
                            'target_date'=>$deviation->target_date,
                            'customer_id'=>$customer_id,
                            'created_by'=>$loggedUserId,
                            'notification_identifier'=>'mimjtask_done',
                            'id' =>$dev_id,
                            'current_status_id'=>$requestedData['id'],
                            'nextStatusId'=>"",
                            'prevStatusId'=>"",
                        ];
                        
                        $this->loadComponent('CommonMailSending');
                        $this->CommonMailSending->email_details($plugin,$controller,$method,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                        $this->loadComponent('QmsNotification');
                        $this->QmsNotification->notificaion_details($plugin,"Deviation",$method,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                      
                  }
                }
                
                if($requestedData['status'] == 'WIP'){
                    
                    $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
                  //  $nextStatussId = $this->WiseWorks->getNextStep($nextStatusId,$dev_id);
                    $title = "Deviation ".$deviation->reference_number." Update Task is in WIP";
                    
                    $notificationData = [
                        'selected_users' => $loggedUserId,
                        'reference_number' =>$deviation->reference_number,
                        'title' =>$title,
                        'category'=>$deviation->classification,
                        'against'=>$deviation->against,
                        'target_date'=>$deviation->target_date,
                        'customer_id'=>$customer_id,
                        'created_by'=>$loggedUserId,
                        'notification_identifier'=>'createactionWIP_done',
                        'id' =>$dev_id,
                        'current_status_id'=>$requestedData['id'],
                        'nextStatusId'=>"",
                        'prevStatusId'=>"",
                    ];
                    
                    $this->loadComponent('CommonMailSending');
                    $this->CommonMailSending->selected_email_details($customer_id,$customer_location_id,$loggedUserId,$notificationData);
                    $this->loadComponent('QmsNotification');
                    $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    
                }
                //Ended by here by shrirang
                
                
                $this->Flash->success(__('The Action Plan has been updated', 'Deviation'));
                return $this->redirect(['action' => 'devtasks']);
            }else{
                $this->Flash->error(__('The {0} could not be saved. Please, try again.', 'Deviation'));
                return $this->redirect(['action' => 'devtasks']);
            }
        }
        $productMaster = $this->Deviation->ProductsMaster->find('list', ['keyField' => 'id','valueField' => 'product_name'])->where(["customer_id"=>$customer_id,"product_service_type"=>"Product"])->toArray();
        $processMaster = $this->Deviation->ProcessMaster->find('list', ['keyField' => 'id','valueField' => 'process_name'])->where(["customer_id"=>$customer_id])->toArray();
        $department = $this->Deviation->FoundAtDept->find('list', ['keyField' => 'id','valueField' => 'department'])->where(['FoundAtDept.customer_id'=>$customer_id])->toArray();
        $devConfigPlanned=($deviation['isplanned']==1)?"planned":"unplanned";
        $this->loadModel('DeviationConfiguration');
        $devConfig=$this->DeviationConfiguration->find('all',['conditions'=>['DeviationConfiguration.customer_id'=>$customer_id,'DeviationConfiguration.deviation_type'=>$devConfigPlanned]])->last();
        $dev_action_attachment=$this->Deviation->DevActionPlans->DevActionPlanAttachment->find("all")->where(["DevActionPlanAttachment.dev_id"=>$dev_id,"DevActionPlanAttachment.dev_action_plan_id"=>$actionplanid])->toArray();
        $this->loadModel("Dev.DevActionPlans");
        $action_plan_details=$this->DevActionPlans->get($actionplanid);
        if(isset($action_plan_details) && !empty($action_plan_details)){
            if(isset($action_plan_details->isviewed) && ($action_plan_details->isviewed == 0 || $action_plan_details->isviewed == "0")){
                $action_plan_details=$this->DevActionPlans->patchEntity($action_plan_details,['isviewed'=>1]);
                $this->DevActionPlans->save($action_plan_details);
            }
        }
        $this->loadModel("ActionTaskStatus");
        $actionTaskStatus= $this->ActionTaskStatus->find('list',[
            'keyField' => 'name','valueField' => 'display_name',
            'conditions'=>['enable_for'=>1,'is_active'=>1]
        ])->toArray();
        
        //get OtherMaster system type add list Against DropDown
        $this->loadModel("OtherMaster");
        $otherMasters = $this->OtherMaster->find('list',[
            'keyField' => 'id',
            'valueField' => 'name',
            'groupField' => 'type'
        ])->where(["customer_id"=>$customer_id,'plugin_name'=>$plugin])->toArray();
        
        $arr=array("Process"=>$processMaster,"Product"=>$productMaster);
        $otherMasters=array_merge($otherMasters,$arr);
        foreach ($otherMasters as $key =>$val)
        {
            $val = str_replace("'", '', $val);
            $otherMasters[$key]=$val;
        }
        $cr_againstList = array();
        foreach($otherMasters as $key=>$value){
            $cr_againstList[$key]=$key;
        }
        
        $this->loadModel("Users");
        $userCondition=array("Users.customer_id"=>$customer_id,"Users.active"=>1);
        $users=$this->Common->getUsersArray($userCondition);
        $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$dev_id,'DevStatusLog.action_taken !='=>'Reject'])->last();
        
        $this->loadModel("Dev.DevInvestigation");
        $devInvestigation=$this->DevInvestigation->find('all')->where(['dev_id'=>$dev_id])->first();
        
        $this->set(compact('preLog','devInvestigation','users','department','processMaster','productMaster','customer_id','loggedUserId','devConfig','deviation','dev_action_attachment','actionTaskStatus','otherMasters'));
    }
    public function createactions($id = null, $currentStatusId=null, $nextStatusId=null, $prevStatusId=null)
    {
        $id=($id==null)?null:decryptVal($id);
        $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
        $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
        $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
        $statusData=array("current_id"=>$currentStatusId,"next_id"=>$nextStatusId,"prev_id"=>$prevStatusId);
        $deviation_data = $this->Deviation->get($id, [
            'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                'DevStatusLog'=>['DevNextActionBy'=>['fields'=>['userfullname']]],
                "DevOwner"=>['fields'=>['userfullname']],'DevActionPlans'
            ],
        ]);
        $isplanned='';
        if($deviation_data['isplanned']==2){
            $isplanned="un";
        }
        $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
        $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        
        $loggedUserDeptId=$this->request->getSession()->read('Auth')['departments_id'];
        $plugin=$this->request->getParam('plugin');
        $controller=$this->request->getParam('controller');
        $method=$this->request->getParam('action');
        
        $this->loadComponent('Common');
        //$createdBy=!empty($deviation_data['CreatedByUser'])?$deviation_data['CreatedByUser']['userfullname']:'';
        //$lastrecord=!empty($deviation_data['dev_status_log'])?count($deviation_data['dev_status_log']):0;
        $verifier=!empty($deviation_data['DevOwner'])?$deviation_data['DevOwner']['userfullname']:'';
        
        if(!$this->Authorization->can($deviation_data, 'createactions')){
            $this->Flash->error(__('Only '.$verifier.' (Owner) have permission to verify deviation.'));
            return $this->redirect(['action' => 'index']);
        }
        $this->getdeviationApplicableSteps($id);
        
        //added by shrirang
        $this->loadModel("Dev.DevStatusLog");
        $this->loadComponent('WiseWorks');
        $allSteps=$this->WiseWorks->getAllSteps();
        $status_step = $this->WiseWorks->getSingleStep($currentStatusId,$allSteps);
        $prevStatusIds = $this->WiseWorks->getPreviousStep($currentStatusId,$id);
        $preLogs = $this->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$prevStatusIds,"DevStatusLog.step_complete"=>1])->last();
 
        $this->getTransPassData($status_step);
        //ended here
        
        if ($this->request->is(['patch', 'post', 'put'])) {
            $requestData=$this->request->getData();
            $dev_status_log=$requestData['dev_status_log'];
            if (isset($preLogs)) {
                $this->DevStatusLog->updateAll(array('next_action_by' => $loggedUserId), array('id' => $preLogs->id));
            }
            if(trim($requestData['removePlans'])!=""){
                $this->loadModel('Dev.DevActionPlans');
                $deleteDeviationPlans=$this->DevActionPlans->find("all",[
                    "conditions"=>[ "id IN ( ".$requestData['removePlans']." )"],
                ])->toArray();
                if(!empty($deleteDeviationPlans) && count($deleteDeviationPlans) > 0){
                    $this->loadComponent('Common');
                    $this->Common->auditTrailForDeleteAll(array_column($deleteDeviationPlans,'id'),'dev_action_plans','deviation',$id);
                    unset($requestData['removePlans']);
                }
            }
            
            if(isset($requestData['submitVerify'])){
                $requestData['dev_status_master_id']=$currentStatusId;
            }
             //dev_action_plan_log
            foreach ($requestData['dev_action_plans'] as $key => $value){
                
                if(empty($value['id'])){
                    $requestData['dev_action_plans'][$key]['dev_action_plan_log'][0]['dev_id'] = $id;
                    $requestData['dev_action_plans'][$key]['dev_action_plan_log'][0]['status'] = $value['status'];
                    $requestData['dev_action_plans'][$key]['dev_action_plan_log'][0]['created_by'] = $loggedUserId;
                }
            }
            //End dev_action_plan_log
            
            $deviation_data = $this->Deviation->patchEntity($deviation_data, $requestData,
                ["associated"=>["DevActionPlans.DevActionPlanLog"]] );
            if ($result=$this->Deviation->save($deviation_data)) {
                $dev_id=$id;
                if(isset($requestData['submitVerify'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                    }
                }
                if(isset($dev_status_log['id']) && $dev_status_log['id'] != ''){
                    $status_log = $this->Deviation->DevStatusLog->get($dev_status_log['id']);
                }else{
                    $status_log = $this->Deviation->DevStatusLog->newEmptyEntity();
                }
                $status_log=$this->Deviation->DevStatusLog->patchEntity($status_log,$dev_status_log);
                $this->Deviation->DevStatusLog->save($status_log);
                
                $dev_status=$this->loadModel('DevStatusMaster');
                $nextstatus= $dev_status->find()->select(['status'])->where(['id =' => $nextStatusId])->first();
                $nextstatus=$nextstatus->status;
                //added by shrirang
                if(isset($requestData['submitVerify'])){
                    $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
                   
                    $nextStatussId = $this->WiseWorks->getNextStep($nextStatusId,$dev_id);
                    $title = "Deviation ".$result->reference_number." Create Action done";
                    foreach($result->dev_action_plans as $val){
                    $notificationData = [
                        'selected_users' => $val['assigned_to'],
                        'reference_number' =>$result->reference_number,
                        'title' =>$title,
                        'category'=>$result->classification,
                        'against'=>$result->against,
                        'target_date'=>$result->target_date,
                        'customer_id'=>$customer_id,
                        'created_by'=>$loggedUserId,
                        'notification_identifier'=>'createaction_done',
                        'id' =>$dev_id,
                        'current_status_id'=>$val['id'],
                        'nextStatusId'=>"",
                        'prevStatusId'=>"",
                    ];
                    
                    $this->loadComponent('CommonMailSending');
                    $this->CommonMailSending->selected_email_details($customer_id,$customer_location_id,$loggedUserId,$notificationData);
                    $this->loadComponent('QmsNotification');
                    $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    //Ended by here by shrirang
                    }
                    
//                     $title="Deviation ".$deviation_data['reference_number']." Sent For ".$nextstatus;
//                     if(isset($deviation_data['created_by']) && ($deviation_data['created_by'] != '')){
//                         $notification=new SimpleNotification([
//                             "notification_inbox_data"=>[
//                                 "customer_id"=>$customer_id,
//                                 "created_by"=>$loggedUserId,
//                                 "user_type"=>"Users",   // accepts User|Groups|Departments
//                                 "user_reference_id"=>$deviation_data['created_by'], // relavtive id
//                                 "title"=>$title, // title of notification
//                                 "comments"=>"Deviation Sent For QA Approval", // content of notification
//                                 "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
//                                 "model_reference_name"=>"Deviation", // for which plugin reference name   if required
//                                 "model_reference_id"=>$dev_id, //   if required
//                                 "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id], // link to redirect to user.
//                                 "type"=>"Info",
//                             ],
//                         ]);
//                         $notification->send();
//                     }
                    
//                     $title_verifier='Deviation - '.$deviation_data['reference_number']." Request To ".$nextstatus;
//                     if(isset($dev_status_log['next_action_by']) && ($dev_status_log['next_action_by'] != '')){
//                         $notification_v=new SimpleNotification([
//                             "notification_inbox_data"=>[
//                                 "customer_id"=>$customer_id,
//                                 "created_by"=>$loggedUserId,
//                                 "user_type"=>"Users",   // accepts User|Groups|Departments
//                                 "user_reference_id"=>$dev_status_log['next_action_by'], // relavtive id
//                                 "title"=>$title_verifier, // title of notification
//                                 "comments"=>"Deviation QA Approval Request", // content of notification
//                                 "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
//                                 "model_reference_name"=>"Deviation", // for which plugin reference name   if required
//                                 "model_reference_id"=>$dev_id, //   if required
//                                 "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"approveqa", $dev_id], // link to redirect to user.
//                                 "type"=>"Action",
//                             ],
//                         ]);
//                         $notification_v->send();
//                     }
//                     if($requestData['config_investigate_approve'] == 'false'){
//                         $title_action="Deviation ".$deviation_data['reference_number']." Implementation Task";
//                         $updateActionPlan=$this->Deviation->DevActionPlans->updateAll(['action_for'=>'Implement'],['action_for'=>'ImplementPlan','dev_id'=>$dev_id]);
//                         $action_plans=$this->Deviation->DevActionPlans->find('all',['conditions'=>['dev_id'=>$dev_id]]);
//                         if($action_plans != ''){
//                             foreach($action_plans as $dev_action_plan){
//                                 if($dev_action_plan->action_for == 'ImplementPlan' && $dev_action_plan->id == " " ){
//                                     $notification=new SimpleNotification([
//                                         "notification_inbox_data"=>[
//                                             "type"=>'Action',
//                                             "customer_id"=>$dev_action_plan->assigned_to,
//                                             "created_by"=>$loggedUserId,
//                                             "user_type"=>"Users",   // accepts User|Groups|Departments
//                                             "user_reference_id"=>$dev_action_plan->assigned_to, // relavtive id
//                                             "title"=>$title_action, // title of notification
//                                             "comments"=>"Deviation Request for Implementation Task", // content of notification
//                                             "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
//                                             "model_reference_name"=>"DevActionPlans", // for which plugin reference name   if required
//                                             "model_reference_id"=>$dev_action_plan->id, //   if required
//                                             "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"devtasks",$isplanned] ,// link to redirect to user.
//                                             "type"=>"Task",
                                            
//                                         ],
//                                     ]);
//                                     $notification->send();
//                                 }
//                             }
//                         }
//                     }
                }

                if(isset($requestData['submitReject'])){
                    $dev_id = $id;
                    $this->loadModel('DevStatusLog');
                    $this->loadModel('DevStatusMaster');
                    $rejectComment =isset($dev_status_log['next_action_by_comments'])?$dev_status_log['next_action_by_comments']: "";
                    $lastuser= $this->DevStatusLog->find()->select(['action_by'])->where(['dev_id =' => $dev_id,"dev_status_master_id"=>1])->first();
                    $lastuserid=$lastuser->action_by;
                    $laststep= $this->DevStatusMaster->find()->select(['form_name','controller_name'])->where(["id"=>1])->first();
                    $controller=$laststep->controller_name;
                    $action=$laststep->form_name;
                    //added by shrirang
                   
                    $current_status_id = 1;
                    $nextStatussId = $this->WiseWorks->getNextStep($current_status_id,$dev_id);
                    $prevsStatusId = $this->WiseWorks->getPreviousStep($nextStatussId,$dev_id);

                    $title = $result->reference_number."Investigatioin Rejected";
                    $notificationData = [
                        'selected_users' => $lastuserid,
                        'reference_number' =>$result->reference_number,
                        'title' =>$title,
                        'against'=>$result->against,
                        'target_date'=>$result->target_date,
                        'customer_id'=>$customer_id,
                        'created_by'=>$loggedUserId,
                        'notification_identifier'=>'HeadApproval_rejected',
                        'id' =>$dev_id,
                        'current_status_id'=>$current_status_id,
                        'nextStatusId'=>$nextStatussId,
                        'prevStatusId'=>$prevsStatusId
                    ];
                    $this->loadComponent('CommonMailSending');
                    $this->CommonMailSending->selected_email_details($customer_id,$customer_location_id,$loggedUserId,$notificationData);
                    $this->loadComponent('QmsNotification');
                    $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    //Ended by here by shrirang
                    if(isset($dev_status_log['id']) && $dev_status_log['id'] != ''){
                        $lastLogId = $dev_status_log['id'];
                    }
                    else {
                        $lastLogId=null;
                    }
                    $this->rejectstatus($dev_id,$prevStatusId,$currentStatusId,$rejectComment,$lastLogId);
                    //return $this->redirect(['action' => 'rejectstatus',$dev_id,1,2]);
                    
                     $this->Flash->error(__('The deviation investigations has been submitted'));
                     return $this->redirect(['controller'=>'Deviation','action' => 'index']);
                    }
                
                $this->Flash->success(__('The deviation has been saved.'));
                
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$currentStatusId])->last();
        $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$prevStatusId])->last();
         $userCondition=array("Users.customer_id"=>$customer_id,"Users.active"=>1);
        $this->loadModel("Users");
        $nextApprovalList = $this->Users->find('list', ['keyField' => 'id','valueField' => 'full_name'])->where($userCondition)->toArray();
        $usersQa = $this->Common->getDeptUsers(['Users.active' => 1, 'Users.customer_id' =>$customer_id,'Users.base_location_id' => $customer_location_id, 'Users.departments_id' => 1]);

        $this->loadModel("Users");
        $approversCondition=array("plugin"=>$plugin,"controller"=>$controller,"element_name"=>"cqa_approve","is_auth"=>1,'RolewisePermissions.can_approve'=>1,'CustomerRoles.customer_id'=>$customer_id);
        $approvers=$this->Common->getAuthList($approversCondition,$loggedUserDeptId);
        $this->loadModel('DevAttachments');
        $condition_file=array(
            "dev_id"=>$id,
            "doc_step_attachment"=>'investigate_doc'
        );
        $investigateAttachmentfile = $this->DevAttachments->find("all",["conditions"=>$condition_file])->toArray();
        $this->set('investigateAttachmentfile',$investigateAttachmentfile);
        $this->set(compact('statusData','approvers','currentLog','preLog','nextApprovalList','usersQa'));
        $this->getDeviationData($id,true);
        $this->viewBuilder()->setTemplate("createactions");
        $this->set('DateTimeFormat',$this->DateTimeFormat); //for passing variable to template
    }
    
    public function investigateapprove($id = null, $currentStatusId=null, $nextStatusId=null, $prevStatusId=null,$optionalRejetStatusId=null)
    {
        $id=($id==null)?null:decryptVal($id);
        $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
        $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
        $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
        $optionalRejetStatusId=($optionalRejetStatusId==null)?null:decryptVal($optionalRejetStatusId);
        $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
        $plugin=$this->request->getParam('plugin');
        $controller=$this->request->getParam('controller');
        $method=$this->request->getParam('action');
        $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
        $deviation_data = $this->Deviation->get($id, [
            'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                'DevStatusLog',"DevAccess",'DevInvestigation','DevAssessment'
            ],
        ]);
        $isplanned='';
        if($deviation_data['isplanned']==2){
            $isplanned="un";
        }
        $this->getdeviationApplicableSteps($id);
        $customer_id=$deviation_data['customer_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        $isInvestigationCompleted=0;$isActionPlanCompleted=0;$isCQA=0;
//         if(!empty($deviation_data['dev_status_log'])){
//             foreach($deviation_data['dev_status_log'] as $row){
//                 if($row['dev_status_master_id']==7 && $row['step_complete']==1){
//                     $isInvestigationCompleted=1;
//                 }
//                 if($row['dev_status_master_id']==8 && $row['step_complete']==1){
//                     $isActionPlanCompleted=1;
//                 }
//                 if(!empty($deviation_data['dev_investigation'])){
//                     if($deviation_data['dev_investigation'][0]['final_classification']=="Critical"){
//                         if($row['dev_status_master_id']==9 && $row['step_complete']==1){
//                             $isCQA=1;
//                         }else{
//                             $isCQA=0;
//                         }
//                     }
//                 }
//             }
//         }
$deviation_data->previousStepId = $prevStatusId ;
        




        if(!$this->Authorization->can($deviation_data, 'investigateapprove')){
//             if($isInvestigationCompleted == 0 || $isActionPlanCompleted == 0 || $isCQA==0){
//                 $this->Flash->error(__('Investigation and Create Action steps must be completed to approve the Investigation.'));
//             }else {
//                 $this->Flash->error(__('Only selected Department Head Members have permission to approve deviation.'));
//             }
            $this->Flash->error(__('Only selected Department Head Members have permission to approve deviation.'));
            return $this->redirect(['action' => 'index']);
        }
        //added by shrirang
        $this->loadComponent('WiseWorks');
        $allSteps=$this->WiseWorks->getAllSteps();
        $status_step = $this->WiseWorks->getSingleStep($currentStatusId,$allSteps);
        $this->getTransPassData($status_step);
        $nextElementName=$this->WiseWorks->getSingleStep($currentStatusId,$allSteps);
        $nextStepName=$this->WiseWorks->getSingleStep($nextElementName->next_step_status_master_id,$allSteps);
        
        //ended here
        
        if ($this->request->is(['patch', 'post', 'put'])) {
            $requestData=$this->request->getData(); 
            unset($requestData['rca_method_data']);
            $dev_status_log=$requestData['dev_status_log'];
            if(isset($requestData['submitApprove'])){
                $requestData['dev_status_master_id']=$currentStatusId;
            }
            $deviation_data = $this->Deviation->patchEntity($deviation_data, $requestData);
//             debug($deviation_data);die;
            if ($result=$this->Deviation->save($deviation_data)) {
                $dev_id=$id;
                $status=$dev_status_log['action_taken'];
                if(isset($requestData['submitApprove'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                        
                        $status_log = $this->Deviation->DevStatusLog->newEmptyEntity();
                        $status_log=$this->Deviation->DevStatusLog->patchEntity($status_log,$dev_status_log);
                      $log =  $this->Deviation->DevStatusLog->save($status_log);
                    }
                    //added by shrirang
                    $nextStatussId = $this->WiseWorks->getNextStep($nextStatusId,$dev_id);
                    $title = "Deviation ".$result->reference_number." Investigate Approval done";
                    $notificationData = [
                      //  'selected_users' => $result->created_by,
                        'reference_number' =>$result->reference_number,
                        'title' =>$title,
                        'category'=>$result->dev_investigation[0]['final_classification'],
                        'against'=>$result->against,
                        'target_date'=>$result->target_date,
                        'customer_id'=>$customer_id,
                        'created_by'=>$loggedUserId,
                        'notification_identifier'=>'investigateapproval_done',
                        'id' =>$dev_id,
                        'current_status_id'=>$nextStatusId,
                        'nextStatusId'=>$nextStatussId,
                        'prevStatusId'=>$currentStatusId
                    ];
                    
                    $this->loadComponent('CommonMailSending');
                    $this->CommonMailSending->email_details($plugin,$controller,$method,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    $this->loadComponent('QmsNotification');
                    $this->QmsNotification->notificaion_details($plugin,$controller,$method,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    //Ended by here by shrirang
                    //debug($requestData);
//                     $this->loadComponent('Capa');
//                     if($requestData['capa_required']){
//                         $this->loadModel("Users");
//                         $user = $this->Users->find('all',['conditions' => array('Users.id' => $requestData['dev_status_log']['next_action_by'])])->first();
//                         $action_by=$this->request->getSession()->read('Auth')['id'];
//                         $capaData=array(
//                             'customer_id' => $deviation_data['customer_id'],
//                             'customer_locations_id' => $deviation_data['customer_locations_id'],
//                             'priority' => 'High',
//                             'plugin' => 'Deviation',
//                             'model_reference_name'=>'Dev.Deviation',
//                             'model_reference_id'=>$dev_id,
//                             'against' => $deviation_data['against'],
//                             'type' => 'Internal',
//                             'dept_id' => $user->departments_id,
//                             'primary_contact' => $deviation_data['created_by'],
//                             //'capa_for_dept' =>$Dev['for_dept'],
//                             'found_at_dept' => $deviation_data['found_at_dept'],
//                             'title' => $deviation_data['title'],
//                             'description' => $deviation_data['description'],
//                             'capa_status_master_id' => '1',
//                             'created'=>date($this->DateTimeFormat),
//                             'product_master_id'=>$deviation_data['product_master_id'],
//                             'process_master_id'=>$deviation_data['process_master_id'],
//                             'created_by'=>$action_by,
//                             'is_systems_generated'=>1,
//                         );
//                         if (isset($requestData['capa_is'])) {
//                             $capaData['capa_is'] = $requestData['capa_is'];
//                         }else
//                         {
//                             $capaIs= $this->Deviation->DevAssessment->find("All")
//                             ->where(["dev_id"=>$id,"type"=>'Final'])->last();
//                             $capaData['capa_is']=$capaIs->capa_is;
//                         }
                       
//                         $saveCapa = $this->Capa->addCapa($capaData,$action_by);
//                     }
                    
                    $title_action="Deviation ".$deviation_data['reference_number']." Implementation Task";
                    $updateActionPlan=$this->Deviation->DevActionPlans->updateAll(['action_for'=>'Implement'],['action_for'=>'ImplementPlan','dev_id'=>$dev_id]);
                    $action_plans=$this->Deviation->DevActionPlans->find('all',['conditions'=>['dev_id'=>$dev_id]]);
//                     if($action_plans != ''){
//                         foreach($action_plans as $dev_action_plan){
//                             if($dev_action_plan->action_for == 'Implement'){
//                                 $notification=new SimpleNotification([
//                                     "notification_inbox_data"=>[
//                                         "type"=>'Action',
//                                         "customer_id"=>$customer_id,
//                                         "created_by"=>$loggedUserId,
//                                         "user_type"=>"Users",   // accepts User|Groups|Departments
//                                         "user_reference_id"=>$dev_action_plan->assigned_to, // relavtive id
//                                         "title"=>$title_action, // title of notification
//                                         "comments"=>"Deviation Request for Implementation Task", // content of notification
//                                         "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
//                                         "model_reference_name"=>"DevActionPlans", // for which plugin reference name   if required
//                                         "model_reference_id"=>$dev_action_plan->id, //   if required
//                                         "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"devactionplan", $dev_id,$dev_action_plan->id] ,// link to redirect to user.
//                                         "type"=>"Task",
                                        
//                                     ],
//                                 ]);
//                                 $notification->send();
//                             }
//                         }
//                     }
                }
                
                $dev_status=$this->loadModel('DevStatusMaster');
                $nextstatus= $dev_status->find()->select(['status'])->where(['id =' => $nextStatusId])->first();
                $nextstatus=$nextstatus->status;
                
//                 $title="Deviation ".$deviation_data['reference_number']." Sent For  ".$nextstatus;
//                 if(isset($deviation_data['reported_by']) && ($deviation_data['reported_by'] != '')){
//                     $notification=new SimpleNotification([
//                         "notification_inbox_data"=>[
//                             "customer_id"=>$customer_id,
//                             "created_by"=>$loggedUserId,
//                             "user_type"=>"Users",   // accepts User|Groups|Departments
//                             "user_reference_id"=>$deviation_data['reported_by'], // relavtive id
//                             "title"=>$title, // title of notification
//                             "comments"=>"Deviation Investigation Approval Status - ".$status, // content of notification
//                             "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
//                             "model_reference_name"=>"Deviation", // for which plugin reference name   if required
//                             "model_reference_id"=>$dev_id, //   if required
//                             "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] ,// link to redirect to user.
//                             "type"=>"Info",
//                         ],
//                     ]);
//                     $notification->send();
//                 }
//                 if(isset($deviation_data['created_by']) && ($deviation_data['created_by'] != '')){
//                     $notification=new SimpleNotification([
//                         "notification_inbox_data"=>[
//                             "customer_id"=>$customer_id,
//                             "created_by"=>$loggedUserId,
//                             "user_type"=>"Users",   // accepts User|Groups|Departments
//                             "user_reference_id"=>$deviation_data['created_by'], // relavtive id
//                             "title"=>$title, // title of notification
//                             "comments"=>"Deviation Investigation Approval - ".$status, // content of notification
//                             "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
//                             "model_reference_name"=>"Deviation", // for which plugin reference name   if required
//                             "model_reference_id"=>$dev_id, //   if required
//                             "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] ,// link to redirect to user.
//                             "type"=>"Info",
//                         ],
//                     ]);
//                     $notification->send();
//                 }
                if(isset($requestData['submitReject'])){
                    $critical="No";
                    if(!empty($deviation_data['dev_investigation'])){
                        $critical=$deviation_data['dev_investigation'][0]['final_classification'];
                    }
                                                 
                    return $this->redirect(['action' => 'rejectstatus',$dev_id,$optionalRejetStatusId,$currentStatusId]);
                    
                    $this->loadModel('DevStatusLog');
                    $this->loadModel('DevStatusMaster');
                    $lastuser= $this->DevStatusLog->find()->select(['action_by'])->where(['dev_id =' => $dev_id,"dev_status_master_id"=>$prevStatusId])->first();
                    $lastuserid=$lastuser->action_by;
                    
                    $laststep= $this->DevStatusMaster->find()->select(['form_name','controller_name'])->where(["id"=>$prevStatusId])->first();
                    $controller=$laststep->controller_name;
                    $action=$laststep->form_name;
                    
                    $title = "Your Request of Deviation ".$deviation_data['reference_number']." is Rejected by ".$this->request->getSession()->read('Auth.first_name')." ".$this->request->getSession()->read('Auth.last_name');
                    
                    $notification=new SimpleNotification([
                        "notification_inbox_data"=>[
                            "customer_id"=>$lastuserid,
                            "created_by"=>$loggedUserId,
                            "user_type"=>"Users",   // accepts User|Groups|Departments
                            "user_reference_id"=>$deviation_data['reported_by'], // relavtive id
                            "title"=>$title, // title of notification
                            "comments"=>"Deviation Step Rejected ", // content of notification
                            "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                            "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                            "model_reference_id"=>$dev_id, //   if required
                            "action_link"=>["plugin"=>"Dev", "controller"=>$controller,"action"=>$action, $dev_id], // link to redirect to user.
                            "type"=>"Action",
                        ],
                    ]);
                    $notification->send();
                    
                    /* $this->rejectstatus($dev_id,8,10); */
                }
                $this->Flash->success(__('The deviation has been saved.'));
                
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$currentStatusId,'DevStatusLog.action_taken !='=>'Reject',])->last();
        $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$prevStatusId])->last();
        
        $this->loadModel('DevAttachments');
        $condition_file=array(
            "dev_id"=>$id,
            "doc_step_attachment"=>'investigate_doc'
        );
        $investigateAttachmentfile = $this->DevAttachments->find("all",["conditions"=>$condition_file])->toArray();
        $this->set('investigateAttachmentfile',$investigateAttachmentfile);
        
        $this->set(compact('currentLog','preLog', 'currentStatusId', 'nextStatusId', 'prevStatusId','nextStepName'));
        $this->getDeviationData($id,true);
        $this->viewBuilder()->setTemplate("investigateapprove");
        $this->set('DateTimeFormat',$this->DateTimeFormat); //for passing variable to template
    }
    public function disposition($id = null, $currentStatusId=null, $nextStatusId=null, $prevStatusId=null)
    {
        $id=($id==null)?null:decryptVal($id);
        $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
        $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
        $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
        
        $deviation_data = $this->Deviation->get($id, [
            'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                'DevStatusLog'=>['DevNextActionBy'=>['fields'=>['userfullname']]],
                "DevOwner"=>['fields'=>['userfullname']],'DevDisposition',
            ],
        ]);
        $isplanned='';
        if($deviation_data['isplanned']==2){
            $isplanned="un";
        }
        $this->getdeviationApplicableSteps($id);
        $customer_id=$deviation_data['customer_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        //$createdBy=!empty($deviation_data['CreatedByUser'])?$deviation_data['CreatedByUser']['userfullname']:'';
        //$lastrecord=!empty($deviation_data['dev_status_log'])?count($deviation_data['dev_status_log']):0;
        $owner=!empty($deviation_data['DevOwner'])?$deviation_data['DevOwner']['userfullname']:'';
        
        if(!$this->Authorization->can($deviation_data, 'disposition')){
            $this->Flash->error(__('Only '.$owner.' (Owner) have permission for disposition.'));
            return $this->redirect(['action' => 'index']);
        }
        if ($this->request->is(['patch', 'post', 'put'])) {
            $requestData=$this->request->getData();//debug($requestData);die;
            $dev_status_log=$requestData['dev_status_log'];
            $dev_disposition=isset($requestData['dev_disposition'])?$requestData['dev_disposition'][0]:'';
            if(isset($requestData['removeItems']) && $requestData['removeItems'] != ''){
                $this->loadModel('Dev.DevImpactedItem');
                $deleteDeviationItem=$this->DevImpactedItem->find("all",[
                    "conditions"=>[ "id IN ( ".$requestData['removeItems']." )"],
                ])->toArray();
                if(!empty($deleteDeviationItem) && count($deleteDeviationItem) > 0){
                    $this->loadComponent('Common');
                    $this->Common->auditTrailForDeleteAll(array_column($deleteDeviationItem,'id'),'dev_impacted_item','deviation',$id);
                    unset($requestData['removeItems']);
                }
            }
            
            if(isset($requestData['submitVerify']) || isset($requestData['submitNotCompatible'])){
                $requestData['dev_status_master_id']=$currentStatusId;
            }
            $deviation_data = $this->Deviation->patchEntity($deviation_data, $requestData);
            //debug($deviation_data);die;
            if ($result=$this->Deviation->save($deviation_data)) {
                $dev_id=$id;
                if(isset($requestData['submitVerify']) || isset($requestData['submitNotCompatible'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                    }
                }
                if(isset($dev_status_log['id']) && $dev_status_log['id'] != ''){
                    $status_log = $this->Deviation->DevStatusLog->get($dev_status_log['id']);
                }else{
                    $status_log = $this->Deviation->DevStatusLog->newEmptyEntity();
                }
                $status_log=$this->Deviation->DevStatusLog->patchEntity($status_log,$dev_status_log);
                $this->Deviation->DevStatusLog->save($status_log);
                
                if(isset($requestData['submitVerify']) || isset($requestData['submitNotCompatible'])){
                    $title='Deviation - '.$deviation_data['reference_number']." disposition is done and sent For Verify Closure";
                    if(isset($requestData['submitNotCompatible'])){
                        $title='Deviation - '.$deviation_data['reference_number']." disposition is not compatible and sent For Verify Closure";
                    }
                    if(!empty($deviation_data['dev_access'])){
                        foreach($deviation_data['dev_access'] as $forUserid){
                            if($forUserid->access_for == 'dbmApproval'){
                                $notification_v=new SimpleNotification([
                                    "notification_inbox_data"=>[
                                        "customer_id"=>$customer_id,
                                        "created_by"=>$loggedUserId,
                                        "user_type"=>"Users",   // accepts User|Groups|Departments
                                        "user_reference_id"=>$forUserid->approver_id, // relavtive id
                                        "title"=>$title, // title of notification
                                        "comments"=>"Deviation disposition is done and sent For Verify Closure", // content of notification
                                        "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                                        "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                                        "model_reference_id"=>$dev_id, //   if required
                                        "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] // link to redirect to user.
                                    ],
                                ]);
                                $notification_v->send();
                            }
                        }
                    }
                    if(isset($dev_disposition['performed_by']) && ($dev_disposition['performed_by'] != '')){
                        $notification=new SimpleNotification([
                            "notification_inbox_data"=>[
                                "customer_id"=>$customer_id,
                                "created_by"=>$loggedUserId,
                                "user_type"=>"Users",   // accepts User|Groups|Departments
                                "user_reference_id"=>$dev_disposition['performed_by'], // relavtive id
                                "title"=>$title, // title of notification
                                "comments"=>"Deviation disposition is done and sent For Verify Closure", // content of notification
                                "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                                "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                                "model_reference_id"=>$dev_id, //   if required
                                "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] // link to redirect to user.
                            ],
                        ]);
                        $notification->send();
                    }
                    if(isset($dev_disposition['approved_by']) && ($dev_disposition['approved_by'] != '')){
                        $notification=new SimpleNotification([
                            "notification_inbox_data"=>[
                                "customer_id"=>$customer_id,
                                "created_by"=>$loggedUserId,
                                "user_type"=>"Users",   // accepts User|Groups|Departments
                                "user_reference_id"=>$dev_disposition['approved_by'], // relavtive id
                                "title"=>$title, // title of notification
                                "comments"=>"Deviation disposition is done and sent For Verify Closure", // content of notification
                                "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                                "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                                "model_reference_id"=>$dev_id, //   if required
                                "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] // link to redirect to user.
                            ],
                        ]);
                        $notification->send();
                    }
                    
//                     $title_verifier='Deviation - '.$deviation_data['reference_number']." Implementation Task Verify Request";
//                     if(isset($dev_status_log['next_action_by']) && ($dev_status_log['next_action_by'] != '')){
//                         $notification_v=new SimpleNotification([
//                             "notification_inbox_data"=>[
//                                 "customer_id"=>$customer_id,
//                                 "created_by"=>$loggedUserId,
//                                 "user_type"=>"Users",   // accepts User|Groups|Departments
//                                 "user_reference_id"=>$dev_status_log['next_action_by'], // relavtive id
//                                 "title"=>$title_verifier, // title of notification
//                                 "comments"=>"Deviation Implementation Task Verification Request", // content of notification
//                                 "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
//                                 "model_reference_name"=>"Deviation", // for which plugin reference name   if required
//                                 "model_reference_id"=>$dev_id, //   if required
//                                 "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"verifyclosure", $dev_id] // link to redirect to user.
//                             ],
//                         ]);
//                         $notification_v->send();
//                     }
                }
                
                $this->Flash->success(__('The deviation has been saved.'));
                
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$currentStatusId])->last();
        $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$prevStatusId])->last();
        
        $this->set(compact('currentLog','preLog', 'currentStatusId', 'nextStatusId', 'prevStatusId'));
        $this->getDeviationData($id,true);
        $this->viewBuilder()->setTemplate("disposition");
    }
    public function verifyclosure($id = null, $currentStatusId=null, $nextStatusId=null, $prevStatusId=null)
    {
        $id=($id==null)?null:decryptVal($id);
        $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
        $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
        $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
        $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
        $deviation_data = $this->Deviation->get($id, [
            'contain' => ['DevInvestigation','CreatedByUser'=>['fields'=>['userfullname']],
                'DevStatusLog'=>['DevNextActionBy'=>['fields'=>['userfullname']]],
                "DevOwner"=>['fields'=>['userfullname']],"DevActionPlans",
            ],
        ]);
        $isplanned='';
        if($deviation_data['isplanned']==2){
            $isplanned="un";
        }
        $this->getdeviationApplicableSteps($id);
        $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
        $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        
        $loggedUserDeptId=$this->request->getSession()->read('Auth')['departments_id'];
        $plugin=$this->request->getParam('plugin');
        $controller=$this->request->getParam('controller');
        $method=$this->request->getParam('action');
        
        $this->loadComponent('Common');
        //$createdBy=!empty($deviation_data['CreatedByUser'])?$deviation_data['CreatedByUser']['userfullname']:'';
        //$lastrecord=!empty($deviation_data['dev_status_log'])?count($deviation_data['dev_status_log']):0;
        $owner=!empty($deviation_data['DevOwner'])?$deviation_data['DevOwner']['userfullname']:'';
        
        if(!$this->Authorization->can($deviation_data, 'verifyclosure')){
            $this->Flash->error(__('Only '.$owner.' (Owner) have permission to verify deviation.'));
            return $this->redirect(['action' => 'index']);
        }
        
        //added by shrirang
        $this->loadComponent('WiseWorks');
        $allSteps=$this->WiseWorks->getAllSteps();
        $status_step = $this->WiseWorks->getSingleStep($currentStatusId,$allSteps);
        $this->getTransPassData($status_step);
        $nextElementName=$this->WiseWorks->getSingleStep($currentStatusId,$allSteps);
        $nextStepName=$this->WiseWorks->getSingleStep($nextElementName->next_step_status_master_id,$allSteps);
        
        //ended here
        
        if ($this->request->is(['patch', 'post', 'put'])) {
            $requestData=$this->request->getData();
            $dev_status_log=$requestData['dev_status_log'];
            $action_plan_array=$requestData['dev_action_plans'];
            $config_qa_closure=$requestData['config_qa_final_closure'];
            
            foreach ($requestData['dev_action_plans'] as $k => $actionplan)
            {
                if (isset($actionplan['status']) && $actionplan['status'] == '' ) {
                    $requestData['dev_action_plans'][$k]['status'] = $requestData['dev_action_plans'][$k]['lastStatus'];
                }
                else {
                    continue;
                }
            }
           
            if(isset($requestData['submitVerify'])){
                $requestData['dev_status_master_id']=$currentStatusId;
            }
            $deviation_data = $this->Deviation->patchEntity($deviation_data, $requestData);
            
            if ($result=$this->Deviation->save($deviation_data)) {
                $dev_id=$id;
                
                //dev_action_plan_log
                $this->loadModel('DevActionPlanLog');
                $DevActionPlanLog = $this->DevActionPlanLog->newEmptyEntity();
                $DevActionPlanLogs = [];
                foreach ($requestData['dev_action_plans'] as $key => $value){
                    
                    if(isset($value['status'])){
                        
                        $DevActionPlanLogs['dev_action_plan_id'] = $value['id'];
                        $DevActionPlanLogs['dev_id'] = $id;
                        $DevActionPlanLogs['status'] = $value['status'];
                        $DevActionPlanLogs['modified_by'] = $loggedUserId;
                        $DevActionPlanLogs['created_by'] = $loggedUserId;
                        
                        $DevActionPlanLog = $this->DevActionPlanLog->patchEntity($DevActionPlanLog, $DevActionPlanLogs);
                        $this->DevActionPlanLog->save($DevActionPlanLog);
                        
                    }
                }
               //End dev_action_plan_log
                
                if(isset($requestData['submitVerify'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                    }
                }
                if(isset($dev_status_log['id']) && $dev_status_log['id'] != ''){
                    $status_log = $this->Deviation->DevStatusLog->get($dev_status_log['id']);
                }else{
                    $status_log = $this->Deviation->DevStatusLog->newEmptyEntity();
                }
                $status_log=$this->Deviation->DevStatusLog->patchEntity($status_log,$dev_status_log);
                $log= $this->Deviation->DevStatusLog->save($status_log);
                
                if(isset($requestData['submitVerify'])){
                if($action_plan_array != ''){
                   
                    foreach($action_plan_array as $action_plan){
                       
                            if(isset($action_plan['lastStatus'])){
                                $status=isset($action_plan['lastStatus'])?$action_plan['lastStatus']:'';
                                if($status == 'Close'){
                                    //added by shrirang
                                    $nextStatussId = $this->WiseWorks->getNextStep($nextStatusId,$dev_id);
                                    $title = "Deviation ".$result->reference_number." Task is closed, Verify Closure done";
                                    $notificationData = [
                                        'selected_users' => $log->next_action_by,
                                        'reference_number' =>$result->reference_number,
                                        'title' =>$title,
                                        'category'=>$result->dev_investigation[0]['final_classification'],
                                        'against'=>$result->against,
                                        'target_date'=>$result->target_date,
                                        'customer_id'=>$customer_id,
                                        'created_by'=>$loggedUserId,
                                        'notification_identifier'=>'Verifyclose_done',
                                        'id' =>$dev_id,
                                        'current_status_id'=>$nextStatusId,
                                        'nextStatusId'=>$nextStatussId,
                                        'prevStatusId'=>$currentStatusId
                                    ];
                                    
                                    $this->loadComponent('CommonMailSending');
                                    $this->CommonMailSending->selected_email_details($customer_id,$customer_location_id,$loggedUserId,$notificationData);
                                    $this->loadComponent('QmsNotification');
                                    $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);//Ended by here by shrirang
                            }

                        }
                }
                }
                }
                if(isset($requestData['submitVerify'])){
                    $title="Deviation ".$deviation_data['reference_number']." Verified Closure Completed";
//                     if(isset($deviation_data['created_by']) && ($deviation_data['created_by'] != '')){
//                         $notification=new SimpleNotification([
//                             "notification_inbox_data"=>[
//                                 "customer_id"=>$customer_id,
//                                 "created_by"=>$loggedUserId,
//                                 "user_type"=>"Users",   // accepts User|Groups|Departments
//                                 "user_reference_id"=>$deviation_data['created_by'], // relavtive id
//                                 "title"=>$title, // title of notification
//                                 "comments"=>"Deviation Verified Closure Completed", // content of notification
//                                 "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
//                                 "model_reference_name"=>"Deviation", // for which plugin reference name   if required
//                                 "model_reference_id"=>$dev_id, //   if required
//                                 "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] ,// link to redirect to user.
//                                 "type"=>"Info",
//                             ],
//                         ]);
//                         $notification->send();
//                     }
                    
                    $title_verifier='Deviation - '.$deviation_data['reference_number']." Verified Closure and Final Closed";
                    $action_link=["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id];
                    if($config_qa_closure){
                        $title_verifier='Deviation - '.$deviation_data['reference_number']." Verifed Closure and Sent For Final Close";
                        $action_link=["plugin"=>"Dev", "controller"=>"Deviation","action"=>"finalclosure", $dev_id];
                    }
                    
//                     if(isset($dev_status_log['next_action_by']) && ($dev_status_log['next_action_by'] != '')){
//                         $notification_v=new SimpleNotification([
//                             "notification_inbox_data"=>[
//                                 "customer_id"=>$customer_id,
//                                 "created_by"=>$loggedUserId,
//                                 "user_type"=>"Users",   // accepts User|Groups|Departments
//                                 "user_reference_id"=>$dev_status_log['next_action_by'], // relavtive id
//                                 "title"=>$title_verifier, // title of notification
//                                 "comments"=>"Deviation Verifed Closure and Sent For Final Close", // content of notification
//                                 "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
//                                 "model_reference_name"=>"Deviation", // for which plugin reference name   if required
//                                 "model_reference_id"=>$dev_id, //   if required
//                                 "action_link"=>$action_link,// link to redirect to user.
//                                 "type"=>"Action",
//                             ],
//                         ]);
//                         $notification_v->send();
//                     }
                    
                }
                if(isset($requestData['submitReject'])){
                    $dev_id = $id;
                    $this->loadModel('DevStatusLog');
                    $this->loadModel('DevStatusMaster');
                    $rejectComment =isset($dev_status_log['next_action_by_comments'])?$dev_status_log['next_action_by_comments']: "";
                    $lastuser= $this->DevStatusLog->find()->select(['action_by'])->where(['dev_id =' => $dev_id,"dev_status_master_id"=>1])->first();
                    $lastuserid=$lastuser->action_by;
                    $laststep= $this->DevStatusMaster->find()->select(['form_name','controller_name'])->where(["id"=>1])->first();
                    $controller=$laststep->controller_name;
                    $action=$laststep->form_name;
                    //added by shrirang
                   
                    $current_status_id = 1;
                    $nextStatussId = $this->WiseWorks->getNextStep($current_status_id,$dev_id);
                    $prevsStatusId = $this->WiseWorks->getPreviousStep($nextStatussId,$dev_id);

                    $title = $result->reference_number."Investigatioin Rejected";
                    $notificationData = [
                        'selected_users' => $lastuserid,
                        'reference_number' =>$result->reference_number,
                        'title' =>$title,
                        'against'=>$result->against,
                        'target_date'=>$result->target_date,
                        'customer_id'=>$customer_id,
                        'created_by'=>$loggedUserId,
                        'notification_identifier'=>'HeadApproval_rejected',
                        'id' =>$dev_id,
                        'current_status_id'=>$current_status_id,
                        'nextStatusId'=>$nextStatussId,
                        'prevStatusId'=>$prevsStatusId
                    ];
                    $this->loadComponent('CommonMailSending');
                    $this->CommonMailSending->selected_email_details($customer_id,$customer_location_id,$loggedUserId,$notificationData);
                    $this->loadComponent('QmsNotification');
                    $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    //Ended by here by shrirang
                    if(isset($dev_status_log['id']) && $dev_status_log['id'] != ''){
                        $lastLogId = $dev_status_log['id'];
                    }
                    else {
                        $lastLogId=null;
                    }
                    $this->rejectstatus($dev_id,$prevStatusId,$currentStatusId,$rejectComment,$lastLogId);
                    //return $this->redirect(['action' => 'rejectstatus',$dev_id,1,2]);
                    
                     $this->Flash->error(__('The deviation investigations has been submitted'));
                     return $this->redirect(['controller'=>'Deviation','action' => 'index']);
                    }
                
                $this->Flash->success(__('The deviation has been result.'));
                
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$currentStatusId])->last();
        $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$prevStatusId])->last();
        
        $this->loadModel("ActionTaskStatus");
        $actionTaskStatus= $this->ActionTaskStatus->find('list',[
            'keyField' => 'name','valueField' => 'display_name',
            'conditions'=>['enable_for'=>2,'is_active'=>1]
        ])->toArray();
        $currentactionTaskStatus= $this->ActionTaskStatus->find('list',[
            'keyField' => 'name','valueField' => 'display_name',
            'conditions'=>['enable_for'=>1,'is_active'=>1]
        ])->toArray();
        $this->loadModel("Users");
        $approversCondition=array("plugin"=>$plugin,"controller"=>$controller,"element_name"=>"qa_final_closure","is_auth"=>1,'RolewisePermissions.can_approve'=>1,'CustomerRoles.customer_id'=>$customer_id);
        $approvers=$this->Common->getAuthList($approversCondition,$loggedUserDeptId);
        $this->loadModel('DevAttachments');
        $condition_file=array(
            "dev_id"=>$id,
            "doc_step_attachment"=>'investigate_doc'
        );
        $investigateAttachmentfile = $this->DevAttachments->find("all",["conditions"=>$condition_file])->toArray();
        $this->set('investigateAttachmentfile',$investigateAttachmentfile);
        
        $this->set(compact('approvers','currentactionTaskStatus','currentLog','preLog', 'currentStatusId', 'nextStatusId', 'prevStatusId','actionTaskStatus','nextStepName'));
        $this->getDeviationData($id,true);
        $this->viewBuilder()->setTemplate("verifyclosure");
        $this->set('DateTimeFormat',$this->DateTimeFormat); //for passing variable to template
    }
    public function finalclosure($id = null, $currentStatusId=null, $nextStatusId=null, $prevStatusId=null)
    {
        $id=($id==null)?null:decryptVal($id);
        $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
        $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
        $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
        $plugin=$this->request->getParam('plugin');
        $controller=$this->request->getParam('controller');
        $method=$this->request->getParam('action');
        $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
        $this->loadComponent('Common');
        $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
        $deviation_data = $this->Deviation->get($id, [
            'contain' => ['DevInvestigation','DevAttachments','CreatedByUser'=>['fields'=>['userfullname']],
                'DevStatusLog'=>['DevNextActionBy'=>['fields'=>['userfullname']]],
                "DevOwner"=>['fields'=>['userfullname']],
            ],
        ]);
        $isplanned='';
        if($deviation_data['isplanned']==2){
            $isplanned="un";
        }
        $customer_id=$deviation_data['customer_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        //$createdBy=!empty($deviation_data['CreatedByUser'])?$deviation_data['CreatedByUser']['userfullname']:'';
        $lastrecord=!empty($deviation_data['dev_status_log'])?count($deviation_data['dev_status_log']):0;
        $approver=($lastrecord > 0)?$deviation_data['dev_status_log'][$lastrecord-1]['DevNextActionBy']['userfullname']:'';
        $owner=!empty($deviation_data['DevOwner'])?$deviation_data['DevOwner']['userfullname']:'';
        $deviation_data->previousStepId = $prevStatusId ;

        if(!$this->Authorization->can($deviation_data, 'finalclosure')){
            $this->Flash->error(__('Only '.$approver.' have permission for Final Close Deviation.'));
            return $this->redirect(['action' => 'index']);
        }
        $this->getdeviationApplicableSteps($id);
        
        //added by shrirang
        $this->loadComponent('WiseWorks');
        $allSteps=$this->WiseWorks->getAllSteps();
        $status_step = $this->WiseWorks->getSingleStep($currentStatusId,$allSteps);
        $this->getTransPassData($status_step);
        //ended here
        
        //DevSettingsDataForFinalClosure
        $this->loadModel("DevSettings");
        $devSettings = $this->DevSettings->find("list",['keyField'=>"element_name", "valueField"=>"properties", 'conditions' => array('DevSettings.customer_id' => $customer_id, "DevSettings.customer_locations_id"=>$customer_location_id)])->toArray();

        if ($this->request->is(['patch', 'post', 'put'])) {
            $requestData=$this->request->getData();
            
            
            //attachment code here.
            $this->loadModel("DevAttachment");
            $dev_attachments=$requestData['dev_attachments'];
            unset($requestData['dev_attachments']);
            foreach($dev_attachments as $j=>$dev_attachment){
                if(isset($dev_attachment["attachment_name"]) && $dev_attachment["attachment_name"]!=""){
                    $attachments=$dev_attachment["attachment_name"];
                    if($attachments->getError()==0){
                        $filename=date("YmdHis").$attachments->getClientFilename();
                        $tmp_name=$attachments->getStream()->getMetadata('uri');
                        $upload=QMSFile::moveUploadedFile($tmp_name,"deviation/".$id.DS.$filename,$customer_id);
                        $dev_attachments[$j]['file_name']=$filename;
                        $dev_attachments[$j]['dev_id']=$id;
                        $dev_attachments[$j]['doc_step_attachment']='close_doc';
                    }else{
                        unset($dev_attachments[$j]['attachment_name']);
                    }
                }
            }
            $requestData['dev_attachments']=$dev_attachments; 
            //Attachment code end here
            $dev_status_log=$requestData['dev_status_log'];
            $suppliers_involved=$requestData['suppliers_involved'];
            $suppliers_has_issues=$requestData['suppliers_has_issues'];
            
            $suppliersInvolved=isset($suppliers_involved)?json_encode($suppliers_involved):'';
            $suppliersHasIssues=isset($suppliers_has_issues)?json_encode($suppliers_has_issues):'';
            
            if(isset($requestData['submitVerify'])){
                $requestData['dev_status_master_id']=$currentStatusId;
            }
            $deviation_data = $this->Deviation->patchEntity($deviation_data, $requestData);
            $result=$this->Deviation->save($deviation_data);
            if ($result) {
                $dev_id=$id;
                $this->Deviation->updateAll(array('supplier_involved' => $suppliersInvolved,'supplier_has_issue'=>$suppliersHasIssues), array('id' => $dev_id));
                if(isset($requestData['submitVerify'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                    }
                }
                if(isset($dev_status_log['id']) && $dev_status_log['id'] != ''){
                    $status_log = $this->Deviation->DevStatusLog->get($dev_status_log['id']);
                }else{
                    $status_log = $this->Deviation->DevStatusLog->newEmptyEntity();
                }
                $status_log=$this->Deviation->DevStatusLog->patchEntity($status_log,$dev_status_log);
                $this->Deviation->DevStatusLog->save($status_log);
                
                $this->loadModel("NotificationInbox");
                $this->loadModel("NotificationsInboxStatus");
                $conditions="NotificationInbox.title like '%$deviation_data->reference_number%'";
                
                $notifications_ids=$this->NotificationInbox->find('list',[
                    'fields'=>["NotificationInbox.id",
                    ],
                    'conditions'=>$conditions,
                ])->toArray();
                if(isset($notifications_ids) && !empty($notifications_ids)){
                $noteids=array_keys($notifications_ids);
                $this->NotificationsInboxStatus->updateAll(
                    array('status' => 'READ'),
                    array('notification_inbox_id IN' => $noteids)
                    );
                }
                if(isset($requestData['submitVerify'])){
                    if($suppliers_involved!="" || $suppliers_has_issues!=""){
                        $customer_id=$customer_id;
                        $supplier_id="";
                        $plugins="Dev";
                        $controller="Deviation";
                        $action="view";
                        $reference_id=$dev_id;
                        $rating=1;
                        $rating_for=0;
                        $this->loadComponent("SupplierRatings");
                        $suppliers_involved=$this->SupplierRatings->filterArraySuppliers($suppliers_involved, $suppliers_has_issues);
                        if($suppliers_involved!=""){
                            $cnt=count($suppliers_involved);
                            for($i=0;$i<$cnt;$i++){
                                $supplier_id=$suppliers_involved[$i];
                                $this->SupplierRatings->updateRating($customer_id,$supplier_id,$plugins,$controller,$action,$reference_id,$rating,"0",$rating_for);
                            }
                        }
                        if($suppliers_has_issues!=""){
                            $inCnt=count($suppliers_has_issues);
                            for($i=0;$i<$inCnt;$i++){
                                $supplier_id=$suppliers_has_issues[$i];
                                $this->SupplierRatings->updateRating($customer_id,$supplier_id,$plugins,$controller,$action,$reference_id,$rating,1,$rating_for);
                            }
                        }
                    }
                    if(isset($requestData['submitVerify'])){
                    $title = "Deviation ".$result->reference_number." Deviation Initiated is Closed";
                    $notificationData = [
                     //   'selected_users' => $result->next_action_by,
                        'reference_number' =>$result->reference_number,
                        'title' =>$title,
                        'category'=>$result->dev_investigation[0]['final_classification'],
                        'against'=>$result->against,
                        'target_date'=>$result->target_date,
                        'customer_id'=>$customer_id,
                        'created_by'=>$loggedUserId,
                        'notification_identifier'=>'dev_close',
                        'id' =>$dev_id,
                        'current_status_id'=>"",
                        'nextStatusId'=>"",
                        'prevStatusId'=>""
                    ];
                    
                    $this->loadComponent('CommonMailSending');
                    $this->CommonMailSending->email_details($plugin,$controller,$method,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    $this->loadComponent('QmsNotification');
                    $this->QmsNotification->notificaion_details($plugin,$controller,$method,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    }
//                     if(isset($deviation_data['dev_owner_id']) && ($deviation_data['dev_owner_id'] != '')){
//                         $notification=new SimpleNotification([
//                             "notification_inbox_data"=>[
//                                 "customer_id"=>$customer_id,
//                                 "created_by"=>$loggedUserId,
//                                 "user_type"=>"Users",   // accepts User|Groups|Departments
//                                 "user_reference_id"=>$deviation_data['dev_owner_id'], // relavtive id
//                                 "title"=>$title, // title of notification
//                                 "comments"=>"Deviation Final Closed", // content of notification
//                                 "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
//                                 "model_reference_name"=>"Deviation", // for which plugin reference name   if required
//                                 "model_reference_id"=>$dev_id, //   if required
//                                 "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] ,// link to redirect to user.
//                                 "type"=>"Info",
//                             ],
//                         ]);
//                         $notification->send();
//                     }
//                     if(isset($dev_status_log['next_action_by']) && ($dev_status_log['next_action_by'] != '')){
//                         $notification_v=new SimpleNotification([
//                             "notification_inbox_data"=>[
//                                 "customer_id"=>$customer_id,
//                                 "created_by"=>$loggedUserId,
//                                 "user_type"=>"Users",   // accepts User|Groups|Departments
//                                 "user_reference_id"=>$dev_status_log['next_action_by'], // relavtive id
//                                 "title"=>$title, // title of notification
//                                 "comments"=>"Deviation Final Closed", // content of notification
//                                 "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
//                                 "model_reference_name"=>"Deviation", // for which plugin reference name   if required
//                                 "model_reference_id"=>$dev_id, //   if required
//                                 "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] ,// link to redirect to user.
//                                 "type"=>"Info",
//                             ],
//                         ]);
//                         $notification_v->send();
//                     }
                    
                    
                }
                
                $this->Flash->success(__('The deviation has been saved.'));
                
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$currentStatusId])->last();
        $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$prevStatusId])->last();
        $this->loadModel("Customer");
        
        $this->loadModel('DevAttachments');
        $condition_file=array(
            "dev_id"=>$id,
            "doc_step_attachment"=>'investigate_doc'
        );
        $investigateAttachmentfile = $this->DevAttachments->find("all",["conditions"=>$condition_file])->toArray();
        $this->set('investigateAttachmentfile',$investigateAttachmentfile);
        
        $supplier = $this->Customer->find('list', ['keyField' => 'id','valueField' => 'company_name'])->toArray();
        $this->set(compact('currentLog','preLog','supplier', 'currentStatusId', 'nextStatusId', 'prevStatusId', 'devSettings'));
        $this->getDeviationData($id,true);
        $this->viewBuilder()->setTemplate("finalclosure");
        $this->set('DateTimeFormat',$this->DateTimeFormat); //for passing variable to template
    }
    public function completedtask($isPlanned=null)
    {
        $deviationX = $this->Deviation->newEmptyEntity();
        if(!$this->Authorization->can($deviationX,'index')){
            $this->Flash->error(__('You are not allowed to access Deviation Module'));
            return $this->redirect(["plugin"=>false,"controller"=>"pages",'action' => 'home']);
        }
        $isplannedStaus=1;
        if($isPlanned=="un"){
            $isplannedStaus=2;
        }
        
        $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
        $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        $devConfigPlanned=($isPlanned=='un')?"unplanned":"planned";
        $this->loadModel('DeviationConfiguration');
        $devConfig=$this->DeviationConfiguration->find('all',['conditions'=>['DeviationConfiguration.customer_id'=>$customer_id,'DeviationConfiguration.deviation_type'=>$devConfigPlanned]])->last();
        $this->loadModel("DevStatusMaster");
        $statusList=$this->DevStatusMaster->find('list',['keyField'=>'id','valueField'=>'display_status'])->toArray();
        $this->loadModel("Users");
        $users=$this->Users->find('list',['keyField'=>'id','valueField'=>'userfullname'])->toArray();
        //debug($users);
        if (isset($statusList)) {
            $statusLimit=count($statusList);
        }
        else {
            $statusLimit=0;
        }
        $condition = array();
        $customerLocationData=$this->request->getQuery('customer_locations_id');
        $statusData=$this->request->getQuery('status_master_id');
        $createdData=$this->request->getQuery('created');
        $fromDate=$this->request->getQuery('from_date');
        $toDate=$this->request->getQuery('to_date');
        $user_id=$this->request->getQuery('user_id');
        $referenceNumberData=$this->request->getQuery('reference_number');
        
        if($customerLocationData != ""){ $condition["AND"][]=['OR'=>["Deviation.customer_locations_id"=>$customerLocationData]]; }
        if($statusData != ""){ $condition["AND"][]=['OR'=>["Deviation.dev_status_master_id"=>$statusData]]; }
        //if($createdData != ""){ $condition["AND"][]=['OR'=>["Deviation.created Like '%$createdData%' "]]; }
        if($referenceNumberData != ""){ $condition["AND"][]=['OR'=>["Deviation.reference_number Like '%$referenceNumberData%' "]]; }
        //$condition['AND'] = ['Deviation.dev_status_master_id'=>$statusLimit];
        if($fromDate == null)
        {
            $fromDate = "";
        }
        if($toDate == null)
        {
            $toDate = "";
        }
        if($user_id !=null){
            $condition["AND"][]=['OR'=>["Deviation.reported_by"=>$user_id]];
        }
        if($fromDate != '' && $toDate != ''){
            $condition['AND'][]="date(Deviation.modified) BETWEEN '".$fromDate."' AND '".$toDate."'";
        }
 
        $this->paginate = [
            "contain" => ["InitiatorUser"=>["fields"=>["userfullname"]],'DevStatusLog'=>[
                "sort"=>["DevStatusLog.id"=>"Desc"],
                "conditions"=>["DevStatusLog.action_taken != 'Reject'"]],
                'CustomerLocations'=>["fields"=>["name"]]],
            "conditions"=>["Deviation.customer_id"=>$customer_id,'Deviation.isplanned'=>$isplannedStaus],
            "conditions"=>[$condition,'Deviation.dev_status_master_id'=>$statusLimit],
            "order"=>["id"=>"desc"]
        ];
        
        $alldeviation = $this->Deviation->find('all', [
            'contain' =>["InitiatorUser"=>["fields"=>["userfullname"]],'DevStatusLog'=>[
                "sort"=>["DevStatusLog.id"=>"Desc"],
                "conditions"=>["DevStatusLog.action_taken != 'Reject'"]],
                'CustomerLocations'=>["fields"=>["name"]]],
            "conditions" => ["Deviation.customer_id"=>$customer_id,'Deviation.isplanned'=>$isplannedStaus],
            "conditions"=>[$condition,'Deviation.dev_status_master_id'=>$statusLimit],
            "order"=>["Deviation.id"=>"desc"]
        ])->toArray();   
        $customerLocationsList = $this->Deviation->CustomerLocations->find('list', ['keyField' => 'id','valueField' => 'name'])->where(["CustomerLocations.customer_id"=>$this->request->getSession()->read("Auth")->get("customer_id")])->toArray();
        $deviation = $this->paginate($this->Deviation);
        $this->set(compact('createdData','statusList','deviation','isPlanned','referenceNumberData','customerLocationData','customerLocationsList','statusData','alldeviation','fromDate','toDate','users','user_id'));
        
        if ($this->request->getQuery('export') != null) {
            $conn = $this;
            $CallBackStream = new CallbackStream(function () use ($conn,$alldeviation) {
                try {
                    $conn->viewBuilder()->setLayout("xls");
                    $conn->set(compact('alldeviation'));
                    echo $conn->render('completedtaskexport');
                } catch (Exception $e) {
                    echo $e->getMessage();
                    $e->getTrace();
                }
            });
                return $this->response->withBody($CallBackStream)
                ->withAddedHeader("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
                ->withAddedHeader("Content-disposition", "attachment; filename=Completed Deviation List.xls");
                
        }
    }
    public function reports($isPlanned=null)
    {
        $deviationX = $this->Deviation->newEmptyEntity();
        if(!$this->Authorization->can($deviationX,'reports')){
            $this->Flash->error(__('You are not allowed to access Deviation Reports Module'));
            return $this->redirect(["plugin"=>false,"controller"=>"pages",'action' => 'home']);
        }
        $condition=[];
        $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
        $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        $devConfigPlanned=($isPlanned=='un')?"unplanned":"planned";
        $this->loadModel('DeviationConfiguration');
        $devConfig=$this->DeviationConfiguration->find('all',['conditions'=>['DeviationConfiguration.customer_id'=>$customer_id,'DeviationConfiguration.deviation_type'=>$devConfigPlanned]])->last();
        $statusLimit=13;
        if(!empty($devConfig)){
            if(!$devConfig->qa_closure_required){
                $statusLimit=12;
            }
        }
        $isplannedStaus=1;
        if($isPlanned=="un"){
            $isplannedStaus=2;
        }
        $impactProductquery=[];
        $condition['AND']=["Deviation.customer_id"=>$customer_id];
        $condition['AND']=["Deviation.isplanned"=>$isplannedStaus];
        
        $fromMonth=$this->request->getQuery('from_month');
        $fromYear=$this->request->getQuery('from_year');
        $toMonth=$this->request->getQuery('to_month');
        $toYear=$this->request->getQuery('to_year');
        $status=$this->request->getQuery('status');
        
        $fromDate=($fromMonth != "" && $fromYear != "")?$fromYear.'-0'.$fromMonth."-01" :'';
        $toDate=($toMonth != "" && $toYear != "")?$toYear.'-0'.$toMonth."-31" :'';
        
        if($fromDate != '' && $toDate != ''){
            $condition['AND'][]="date(Deviation.created) BETWEEN '".$fromDate."' AND '".$toDate."'";
        }
        if($status != ''){
            if($status == 'pending'){
                $condition['AND'][]="Deviation.dev_status_master_id < $statusLimit";
            }
            if($status == 'closed'){
                $condition['AND'][]="Deviation.dev_status_master_id >= $statusLimit";
            }
        }
        
        $this->paginate = [
            "contain" => ['DevStatusLog'=>[
                "sort"=>["DevStatusLog.id"=>"Desc"],
                "conditions"=>["DevStatusLog.action_taken != 'Reject'"]],
                'CustomerLocations'=>["fields"=>["name"]],
                'DevStatusMaster','ProductsMaster','ProcessMaster','CreatedByUser'
            ],
            "conditions"=>$condition,
            "order"=>["id"=>"desc"]
        ];
        $deviation = $this->paginate($this->Deviation);
        
        $query = $this->Deviation->find();
        $query->select(['count' => $query->func()->count('id'),'customer_locations_id']);
        $query->where($condition);
        $query->group(["Deviation.customer_locations_id"]);
        $query->toArray();
        $deviationSiteData=json_encode($query);
        
        $productquery = $this->Deviation->find();
        $productquery->select(['count' => $productquery->func()->count('id'),'product_master_id']);
        $productquery->where($condition);
        $productquery->group(["Deviation.product_master_id"]);
        $productquery->toArray();
        $deviationProductData=json_encode($productquery);
        
        $deviation_array=$this->Deviation->find('all',["conditions"=>$condition])->toArray();
        $deviation_id=!empty($deviation_array)?array_column($deviation_array,'id'):[];
        $productCondition=['DevImpactedItem.dev_id'=>$deviation_id];
        
        if(!empty($deviation_id)){
            $impactProductquery = $this->Deviation->DevImpactedItem->find();
            $impactProductquery->select(['count' => $impactProductquery->func()->count('id'),'product_id']);
            $impactProductquery->where(['DevImpactedItem.dev_id IN'=>$deviation_id]);
            $impactProductquery->group(["DevImpactedItem.product_id"]);
            $impactProductquery->toArray();
        }
        
        $impactedProduct=json_encode($impactProductquery);
        
        $customerLocations = $this->Deviation->CustomerLocations->find('all', ['fields' => ['id','name']])->where(["CustomerLocations.customer_id"=>$customer_id])->toArray();
        $customerLocations=json_encode($customerLocations);
        $customerProducts = $this->Deviation->ProductsMaster->find('all', ['fields' => ['id','product_name']])->where(["ProductsMaster.customer_id"=>$customer_id,"ProductsMaster.product_service_type"=>"Product"])->toArray();
        $customerProducts=json_encode($customerProducts);
        $this->set(compact('statusLimit','status','impactedProduct','toYear','fromYear','toMonth','fromMonth','deviationProductData','deviationSiteData','deviation','customerLocations','customerProducts','isPlanned'));
    }
    public function createpdf($id){
        
        $id=($id==null)?null:decryptVal($id);
        $this->Authorization->skipAuthorization();
        $deviation= $this->Deviation->get($id, [
            'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                'DevStatusLog'=>[
                    'DevStatusChangeBy'=>['fields'=>['userfullname']],
                    'DevNextActionBy'=>['fields'=>['userfullname']],
                    "sort"=>["DevStatusLog.id"=>"Desc"],
                ],
                'CustomerLocations'=>["fields"=>["name"]],
                'ProductsMaster','ProcessMaster','FoundAtDept',"DevAttachments",
                "DevImpactedItem"=>['ProductsMaster','Uom'],
                "ProductionLineMaster","DevAssessment",
                "DevActionPlans"=>['DevActionPlanAttachment'],
                "InitContActionBy"=>["fields"=>['userfullname']],
                "ImmediateActionBy"=>["fields"=>['userfullname']],
                "DevAccess","DevContainment"=>['PerformedBy','ApprovedBy'],
                'DevDisposition'=>['PerformedBy','ApprovedBy']
            ],
        ]);
        $customer_id=!empty($deviation)?$deviation['customer_id']:$this->request->getSession()->read('Auth.customer_id');
        $this->loadModel('Customer');
        $CompanyDetails=$this->Customer->get($customer_id,[
            'contain'=>['Countries','State','City']
        ]);
        $companyName=!empty($CompanyDetails)?$CompanyDetails['company_name']:'';
        $companyLogo=!empty($CompanyDetails)?$CompanyDetails['logo_image']:'';
        $companyLocation=!empty($deviation)?!empty($deviation['customer_location'])?$deviation['customer_location']['name']:'':'';
        $companyAddress=!empty($CompanyDetails)?$CompanyDetails['address']:'';
        $pincode=!empty($CompanyDetails)?$CompanyDetails['pin_code']:'';
        $website=!empty($CompanyDetails)?$CompanyDetails['website_address']:'';
        $phone=!empty($CompanyDetails)?$CompanyDetails['company_phone']:'';
        $email=!empty($CompanyDetails)?$CompanyDetails['company_email']:'';
        $countries=!empty($CompanyDetails)?!empty($CompanyDetails['countries'])?$CompanyDetails['countries']['country_name']:'':'';
        $state=!empty($CompanyDetails)?!empty($CompanyDetails['states'])?$CompanyDetails['states']['state_name']:'':'';
        $city=!empty($CompanyDetails)?!empty($CompanyDetails['cities'])?$CompanyDetails['cities']['city_name']:'':'';
        //$this->loadComponent('Pdf');
        //debug($this->Pdf->generatepdf($deviation));die;
        
        $this->set(compact('deviation'));
        $this->viewBuilder()->setLayout("blank");
        $html=$this->render("/Pdf/deviation");
        
        $size='font-size:8pt;';
        $headerHtml="<br/>
                <div class='row' style='text-align:center;$size'>
                    <h1>
                    <img src='storage/customer/".$customer_id."/images/".$companyLogo."' width='20' />
                    ".$companyName."</h1>
                    <p>".$companyAddress.",".$city.",".$state.",".$countries.",".$pincode."<br>
                    Email: ".$email.", Phone: ".$phone.", Website: ".$website."<br>
                    Location: ".$companyLocation."</p>
                </div>
				";
        
        $footerHtml="<div style='text-align:right;padding-bottom:5px;'><span>Page {PAGENO}</span></div>";
        
        $CakePdf = new \Mpdf\Mpdf();
        
        $pdf_file=WWW_ROOT.'upload'. DS.'docs'.DS.$id.'_capa.pdf';
        $totalpagecnt="{nb}";
        
        //$cnt='{PAGENO}  Of '.$totalpagecnt;
        //echo $cnt;die;
        //$headerHtml = str_replace('{PAGECNT}', $cnt, $headerHtml);
        //$footerHtml = str_replace('{PAGECNT}', $cnt, $footerHtml);
        
        $CakePdf->tableMinSizePriority = false;
        $CakePdf->SetHTMLHeader($headerHtml);
        $CakePdf->SetHTMLfooter($footerHtml);
        $CakePdf->AddPage('', // L - landscape, P - portrait
            '', '', '', '',
            5, // margin_left
            5, // margin right
            45, // margin top
            30, // margin bottom
            0, // margin header
            0); // margin footer
            
            $CakePdf->WriteHTML($html);
            
            $CakePdf->Output($pdf_file,'F');
            $pdfFile=new FileLib();
            $pdfFile->preview($pdf_file, "application/pdf" );
            
            return true;
            
    }
    public function addcustomerdeviation($isPlanned=null)
    {
        $deviation = $this->Deviation->newEmptyEntity();
        $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
        $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        if(!$this->Authorization->can($deviation, 'add')){
            $this->Flash->error(__('You dont have permission to create deviation.'));
            return $this->redirect(['action' => 'index',$isPlanned]);
        }
        
        //added by shrirang
        $currentStatusId = 1;
        $this->loadComponent('WiseWorks');
        $allSteps=$this->WiseWorks->getAllSteps();
        $status_step = $this->WiseWorks->getSingleStep($currentStatusId,$allSteps);
        $this->getTransPassData($status_step);
        //ended here
        
        //DevSettingsDataForAddcustomerdeviation
        $this->loadModel("DevSettings");
        $devSettings = $this->DevSettings->find("list",['keyField'=>"element_name", "valueField"=>"properties", 'conditions' => array('DevSettings.customer_id' => $customer_id, "DevSettings.customer_locations_id"=>$customer_location_id)])->toArray();

        if ($this->request->is('post')) {
            $requestData=$this->request->getData();//debug($requestData);die;
            $requestData['created_by_customer']=$customer_id;
            $dev_attachments=$requestData['dev_attachments'];
            $devMoveUpload = $requestData['dev_attachments'];
            $approver_array=!empty($requestData['access'])?$requestData['access']:'';
            if($approver_array != ''){
                $requestData['dev_access'][]=['approver_id'=>$approver_array,'access_for'=>'dbmApproval'];
            }
            unset($requestData['dev_attachments']);
            foreach($dev_attachments as $j=>$dev_attachment){
                if(isset($dev_attachment["file_name"]) && $dev_attachment["file_name"]!=""){
                    
                    $attachments=$dev_attachment["file_name"];
                    if($attachments->getError() == 0){
                        $filename=date("YmdHis").$attachments->getClientFilename();
                        //   $tmp_name=$attachments->getStream()->getMetadata('uri');
                        //                         $filesPathNew="deviation/".$audit_id.DS.$filename;
                        //                         QMSFile::moveUploadedFile($tmp_name,"deviation".DS.$filename,$customer_id);
                        $dev_attachments[$j]['file_name']=$filename;
                    }else{
                        unset($dev_attachments[$j]);
                    }
                }
            }
            $requestData['dev_attachments']=$dev_attachments;
            $dev_status_log=$requestData['dev_status_log'];
            unset($requestData['dev_status_log']);
            if(trim($requestData['removePlans'])!=""){
                $this->loadModel('Dev.DevActionPlans');
                $deleteDeviationPlans=$this->DevActionPlans->find("all",[
                    "conditions"=>[ "id IN ( ".$requestData['removePlans']." )"],
                ])->toArray();
                if(!empty($deleteDeviationPlans) && count($deleteDeviationPlans) > 0){
                    $this->loadComponent('Common');
                    $this->Common->auditTrailForDeleteAll(array_column($deleteDeviationPlans,'id'),'dev_action_plans','deviation',$id);
                    unset($requestData['removePlans']);
                }
            }
            $deviation = $this->Deviation->patchEntity($deviation, $requestData);
            if($isPlanned=="un"){
                $deviation["isplanned"]=2;
            }else{
                $deviation["isplanned"]=1;
            }
            $result=$this->Deviation->save($deviation);
            if ($result) {
                
                foreach($devMoveUpload as $j=>$dev_attachment){
                    if(isset($dev_attachment["file_name"]) && $dev_attachment["file_name"]!=""){
                        $attachments=$dev_attachment["file_name"];
                        if($attachments->getError() == 0){
                            $filename=date("YmdHis").$attachments->getClientFilename();
                            $tmp_name=$attachments->getStream()->getMetadata('uri');
                            $deviationId=$deviation['id'];
                            $filesPathNew="deviation/".$deviationId.DS.$filename;
                            if(QMSFile::fileExistscheck($filesPathNew)){
                                QMSFile::moveUploadedFile($tmp_name,"deviation/".$deviationId.DS.$filename,$customer_id);
                            } else{
                                QMSFile::moveUploadedFile($tmp_name,"deviation/".$deviationId.DS.$filename,$customer_id);
                            }
                            QMSFile::moveUploadedFile($tmp_name,"deviation/".$deviationId.DS.$filename,$customer_id);
                        }else{
                            unset($dev_attachments[$j]);
                        }
                    }
                }
                
                
                $dev_id=$result->get('id');
                $dev_status_log['dev_id']=$dev_id;
                $this->loadModel("Dev.DevStatusLog");
                if(isset($requestData['submitVerify'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                    }
                }
                $devStatusLog = $this->DevStatusLog->newEmptyEntity();
                $devStatusLog = $this->DevStatusLog->patchEntity($devStatusLog, $dev_status_log);
                $this->DevStatusLog->save($devStatusLog);
                
                $title=$result->get('reference_number')." Raised";
                if(isset($requestData['reported_by']) && ($requestData['reported_by'] != '')){
                    $notification=new SimpleNotification([
                        "notification_inbox_data"=>[
                            "customer_id"=>$customer_id,
                            "created_by"=>$loggedUserId,
                            "user_type"=>"Users",   // accepts User|Groups|Departments
                            "user_reference_id"=>$requestData['reported_by'], // relavtive id
                            "title"=>$title, // title of notification
                            "comments"=>"Deviation Raised", // content of notification
                            "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                            "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                            "model_reference_id"=>$dev_id, //   if required
                            "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"view", $dev_id] // link to redirect to user.
                        ],
                    ]);
                    $notification->send();
                }
                
                if(isset($requestData['submitVerify'])){
                    $title_verifier='Deviation - '.$result->get('reference_number')." Request To Department Head Approval";
                    if($approver_array != ''){
                        foreach($approver_array as $forUserid){
                            $notification_v=new SimpleNotification([
                                "notification_inbox_data"=>[
                                    "customer_id"=>$customer_id,
                                    "created_by"=>$loggedUserId,
                                    "user_type"=>"Users",   // accepts User|Groups|Departments
                                    "user_reference_id"=>$forUserid, // relavtive id
                                    "title"=>$title_verifier, // title of notification
                                    "comments"=>"Deviation Department Head Approval Request", // content of notification
                                    "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                                    "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                                    "model_reference_id"=>$dev_id, //   if required
                                    "action_link"=>["plugin"=>"Dev", "controller"=>"Deviation","action"=>"approvedbm", $dev_id] // link to redirect to user.
                                ],
                            ]);
                            $notification_v->send();
                        }
                    }
                }
                
                $this->Flash->success(__('The deviation has been saved.'));
                
                return $this->redirect(['action' => 'index', $isPlanned]);
            }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        $customerLocations = $this->Deviation->CustomerLocations->find('list', ['keyField' => 'id','valueField' => 'name'])->where(["CustomerLocations.customer_id"=>$customer_id]);
        $productMaster = $this->Deviation->ProductsMaster->find('list', ['keyField' => 'id','valueField' => 'product_name'])->where(["customer_id"=>$customer_id,"product_service_type"=>"Product"])->toArray();
        $processMaster = $this->Deviation->ProcessMaster->find('list', ['keyField' => 'id','valueField' => 'process_name'])->where(["customer_id"=>$customer_id]);
        $department = $this->Deviation->FoundAtDept->find('list', ['keyField' => 'id','valueField' => 'department'])->where(['FoundAtDept.customer_id'=>$customer_id]);
        //$productLineMasters = $this->Deviation->ProductLineMasters->find('list', ['limit' => 200]);
        $devArray=Configure::read("devArray");
        
        $this->loadModel("CustomerVendors");
        $suppliersList=$this->CustomerVendors->find('all',[
            'contain'=>['CustomerVendor'],
            'conditions'=>["customer_id=".$customer_id]
        ]);
        $suppliers=$suppliersList->map(function($value,$key){
            return [
                'value'=>$value->vendor_id,
                'text'=>$value->customer_vendor->company_name,
            ];
        });
            
            $customersList=$this->CustomerVendors->find('all',[
                'contain'=>['CustomerVendor'],
                'conditions'=>"vendor_id=".$customer_id
            ]);
            $customers=$customersList->map(function($value,$key){
                return [
                    'value'=>$value->customer_id,
                    'text'=>$value->customer_vendor->company_name,
                ];
            })->toList();
            $userCondition=array("Users.customer_id"=>$customer_id,"Users.active"=>1);
            $this->loadModel("Users");
            $users = $this->Users->find('list', ['keyField' => 'id','valueField' => 'full_name'])->where($userCondition)->toArray();
            $dbmUsers = $this->Users->find('list', ['keyField' => 'id','valueField' => 'full_name'])->where(array_merge($userCondition,['Users.is_dept_head'=>1]))->toArray();
            $this->loadModel("DocMaster");
            $docMaster = $this->DocMaster->find('list', ['keyField' => 'id','valueField' => 'title'])->where(['customer_id'=>$customer_id,'customer_locations_id'=>$customer_location_id])->toArray();
            
            $this->loadModel("Uom");
            $uom = $this->Uom->find('list', ['keyField' => 'id','valueField' => 'unit_name'])->toArray();
            $devIsConfigPlanned=($isPlanned=="un")?"unplanned":"planned";
            $this->loadModel('DeviationConfiguration');
            $devConfig=$this->DeviationConfiguration->find('all',['conditions'=>['DeviationConfiguration.customer_id'=>$customer_id,'DeviationConfiguration.deviation_type'=>$devIsConfigPlanned]])->last();
            
            $dtformat=CustomerCache::read("dateinputformat");
            $un_dev_target_days=CustomerCache::read("un_dev_target_days");
            $un_dev_working_days=CustomerCache::read("un_dev_working_days");
            
            $dt = date($dtformat);//current_date
            $sub_struct_month = ($un_dev_target_days / 30) ;
            $sub_struct_month = floor($sub_struct_month);
            $total_holidays = $sub_struct_month;
            
            $total_target_days_including_holiday = $un_dev_target_days+$total_holidays;
            $days = $total_target_days_including_holiday.'days';
            
            $target_date = date($dtformat, strtotime($dt.$days));
            $resultDays = array('Monday' => 0,
                'Tuesday' => 0,
                'Wednesday' => 0,
                'Thursday' => 0,
                'Friday' => 0,
                'Saturday' => 0,
                'Sunday' => 0);
            
            // change string to date time object
            $startDate = date($dt);
            $endDate = date($target_date);
            //$endDate = new DateTime($target_date);
            $monday = array();
            // iterate over start to end date
            while($startDate <= $endDate){
                // find the timestamp value of start date
                $timestamp = strtotime($startDate); /*  time(); */
                // find out the day for timestamp and increase particular day
                $weekDay = date('l Y-m-d', $timestamp);
                $get_weekday = explode(' ',$weekDay);
                $resultDays[$weekDay][0] = $resultDays[$get_weekday[0]] + 1;
                
                if($get_weekday[0]=='Monday'){
                    $monday[] = $get_weekday[1];
                }
                // increase startDate by 1
                $next_date = strtotime("+1 day", strtotime($startDate));
                $startDate= date($dtformat,$next_date);
                //$startDate->modify('+1 day');
                if($startDate==$endDate){
                    break;
                }
            }
            //$holidays=[];
            $nextMonday=[];
            $i=0;$days=[];
            for($i=0;$i<count($monday);$i++){
                $nextMonday[$i]=$monday[$i];
                if($i==($sub_struct_month-1)){
                    break;
                }
            }
            $date = strtotime($dt);
            $i = 0;
            while($i < $un_dev_target_days)
            {
                //get number of week day (1-7)
                $day = date('N',$date);
                //get just Y-m-d date
                $dateYmd = date("Y-m-d",$date);
                
                if($day <= $un_dev_working_days && !in_array($dateYmd, $nextMonday)){
                    $i++;
                }
                $date = strtotime($dateYmd . ' +1 day');
            }
            $targetDate= date('d-m-Y',$date);
            
            $cond=array(
                "customer_id=".$customer_id." OR vendor_id=".$customer_id
            );
            $oems=[];
            $this->loadModel("CustomerVendors");
            $suppliersList=$this->CustomerVendors->find('all',[
                'conditions'=>$cond
            ])->toArray();
            $customerIds=array();
            foreach($suppliersList as $row){
                if($row['customer_id']==$customer_id){
                    $customerIds[]=$row['vendor_id'];
                }else{
                    $customerIds[]=$row['customer_id'];
                }
            }
            if(count($customerIds)>0){
                $oems_cond=array(
                    "id in (".implode(',',$customerIds).")"
                );
                $this->loadModel('Customer');
                $oems=$this->Customer->find("list", ['keyField' => 'id','valueField' => 'company_name'])->where($oems_cond);
            }
            $this->set(compact('oems','targetDate','docMaster','isPlanned','dbmUsers','devConfig','uom','users','department','devArray','loggedUserId','customer_id','deviation', 'customers', 'customerLocations', 'suppliers', 'productMaster', 'processMaster', 'devSettings'));
    }
    public function cqaapproval($id = null, $currentStatusId=null, $nextStatusId=null, $prevStatusId=null)
    {
        $id=($id==null)?null:decryptVal($id);
        $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
        $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
        $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
        $deviation_data = $this->Deviation->get($id, [
            'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                'DevStatusLog',"DevAccess",'DevInvestigation'
            ],
        ]);
        $isplanned='';
        if($deviation_data['isplanned']==2){
            $isplanned="un";
        }
        $this->getdeviationApplicableSteps($id);
        $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
        $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        
        $loggedUserDeptId=$this->request->getSession()->read('Auth')['departments_id'];
        $plugin=$this->request->getParam('plugin');
        $controller=$this->request->getParam('controller');
        $method=$this->request->getParam('action');
        
        $this->loadComponent('Common');

        if(!$this->Authorization->can($deviation_data, 'CqaApproval')){
            $this->Flash->error(__('Only selected Members have permission to approve deviation.'));
           return $this->redirect(['action' => 'index']);
        }
        $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
        if ($this->request->is(['patch', 'post', 'put'])) {
            $requestData=$this->request->getData();//debug($requestData);die;
            unset($requestData['rca_method_data']);
            $dev_status_log=$requestData['dev_status_log'];
            if(isset($requestData['submitApprove'])){
                $requestData['dev_status_master_id']=$currentStatusId;
            }
            
            $deviation_data = $this->Deviation->patchEntity($deviation_data, $requestData);
          
            if ($reslut=$this->Deviation->save($deviation_data)) {
                $dev_id=$id;
                $status=$dev_status_log['action_taken'];
                if(isset($requestData['submitApprove'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                         
                        $status_log = $this->Deviation->DevStatusLog->newEmptyEntity();
                        $status_log=$this->Deviation->DevStatusLog->patchEntity($status_log,$dev_status_log);
                   
                        $log = $this->Deviation->DevStatusLog->save($status_log);
                    }
                    
                    //added by shrirang
                    $nextStatussId = $this->WiseWorks->getNextStep($nextStatusId,$dev_id);
                    $title = "Deviation ".$reslut->reference_number." CQA Approval done";
                    $notificationData = [
                        'selected_users' => $log->next_action_by,
                        'reference_number' =>$reslut->reference_number,
                        'title' =>$title,
                        'category'=>$reslut->dev_investigation[0]['final_classification'],
                        'against'=>$reslut->against,
                        'target_date'=>$reslut->target_date,
                        'customer_id'=>$customer_id,
                        'created_by'=>$loggedUserId,
                        'notification_identifier'=>'cqaapproval_done',
                        'id' =>$dev_id,
                        'current_status_id'=>$nextStatusId,
                        'nextStatusId'=>$nextStatussId,
                        'prevStatusId'=>$currentStatusId
                    ];
                    
                    $this->loadComponent('CommonMailSending');
                    $this->CommonMailSending->selected_email_details($customer_id,$customer_location_id,$loggedUserId,$notificationData);
                    $this->loadComponent('QmsNotification');
                    $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    //Ended by here by shrirang
                    
                    

                    if($requestData['capa_required']){
                        $action_by=$this->request->getSession()->read('Auth')['id'];
                        $capaData=array(
                            'customer_id' => $deviation_data['customer_id'],
                            'customer_locations_id' => $deviation_data['customer_locations_id'],
                            'priority' => 'High',
                            'plugin' => 'Dev',
                            'model_reference_name'=>'Dev.Deviation',
                            'model_reference_id'=>$deviation_data['id'],
                            'against' => $deviation_data['against'],
                            'type' => 'Internal',
                            'primary_contact' => $deviation_data['created_by'],
                            //'capa_for_dept' =>$Dev['for_dept'],
                            'found_at_dept' => $deviation_data['found_at_dept'],
                            'title' => $deviation_data['title'],
                            'description' => $deviation_data['description'],
                            'capa_status_master_id' => '1',
                            'created'=>date($this->DateTimeFormat),
                            'product_master_id'=>$deviation_data['product_master_id'],
                            'process_master_id'=>$deviation_data['process_master_id'],
                            'created_by'=>$action_by,
                            'is_systems_generated'=>1,
                        );
                        $this->loadComponent('Capa');
                        $this->Capa->addCapa($capaData,$action_by);
                    }
                    
                    $title_action="Deviation ".$deviation_data['reference_number']." Implementation Task";
                    $updateActionPlan=$this->Deviation->DevActionPlans->updateAll(['action_for'=>'Implement'],['action_for'=>'ImplementPlan','dev_id'=>$dev_id]);
                    $action_plans=$this->Deviation->DevActionPlans->find('all',['conditions'=>['dev_id'=>$dev_id]]);

                }
                
                $dev_status=$this->loadModel('DevStatusMaster');
                $nextstatus= $dev_status->find()->select(['status'])->where(['id =' => $nextStatusId])->first();
                $nextstatus=$nextstatus->status;
                

                if(isset($requestData['submitReject'])){
                    return $this->redirect(['action' => 'rejectstatus',$dev_id,$prevStatusId,$currentStatusId]);
                    
                    $this->loadModel('DevStatusLog');
                    $this->loadModel('DevStatusMaster');
                    $lastuser= $this->DevStatusLog->find()->select(['action_by'])->where(['dev_id =' => $dev_id,"dev_status_master_id"=>$prevStatusId])->first();
                    $lastuserid=$lastuser->action_by;
                    
                    $laststep= $this->DevStatusMaster->find()->select(['form_name','controller_name'])->where(["id"=>$prevStatusId])->first();
                    $controller=$laststep->controller_name;
                    $action=$laststep->form_name;
                    
                    $title = "Your Request of Deviation ".$deviation_data['reference_number']." is Rejected by ".$this->request->getSession()->read('Auth.first_name')." ".$this->request->getSession()->read('Auth.last_name');
                    
                    $notification=new SimpleNotification([
                        "notification_inbox_data"=>[
                            "customer_id"=>$lastuserid,
                            "created_by"=>$loggedUserId,
                            "user_type"=>"Users",   // accepts User|Groups|Departments
                            "user_reference_id"=>$deviation_data['reported_by'], // relavtive id
                            "title"=>$title, // title of notification
                            "comments"=>"Deviation Step Rejected ", // content of notification
                            "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                            "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                            "model_reference_id"=>$dev_id, //   if required
                            "action_link"=>["plugin"=>"Dev", "controller"=>$controller,"action"=>$action, $dev_id], // link to redirect to user.
                            "type"=>"Action",
                        ],
                    ]);
                    $notification->send();
                    
                    /* $this->rejectstatus($dev_id,8,10); */
                }
                $this->Flash->success(__('The deviation has been saved.'));
                
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$currentStatusId,'DevStatusLog.action_taken !='=>'Reject',])->last();
        $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$prevStatusId])->last();
        
        $this->loadModel("Users");
        $approversCondition=array("plugin"=>$plugin,"controller"=>$controller,"element_name"=>"qa_approve","is_auth"=>1,'RolewisePermissions.can_approve'=>1,'CustomerRoles.customer_id'=>$customer_id);
        $approvers=$this->Common->getAuthList($approversCondition,$loggedUserDeptId);
        $this->set(compact('currentLog','preLog', 'currentStatusId', 'nextStatusId', 'prevStatusId','approvers'));
        $this->getDeviationData($id,true);
        $this->viewBuilder()->setTemplate("cqaapproval");
        $this->set('DateTimeFormat',$this->DateTimeFormat); //for passing variable to template 
    }
    
    public function devattachmentDelete($attach_id=null,$customer_id=null){
        $this->Authorization->skipAuthorization();
        $attach_id=($attach_id==null)?null:decryptVal($attach_id);
        $customer_id=($customer_id==null)?null:decryptVal($customer_id);
        $this->loadModel("DevAttachments");
        $attachment=$this->DevAttachments->get($attach_id);
        
        if(!empty($attachment)){
            $dev_id=$attachment['dev_id'];
            if(trim($attachment['file_name'])!=""){
                $filesPathNew="deviation/".$dev_id.DS.$attachment['file_name'];
                
                if(QMSFile::delete($filesPathNew,$customer_id)){
                    
                    if ($this->DevAttachments->delete($attachment)) {
                        $this->Flash->success(__('The attachment has been deleted.'));
                    } else {
                        $this->Flash->error(__('The attachment could not be deleted. Please, try again.'));
                    }
                    
                    
                    
                }else{
                    // debug(QMSFile::delete($filesPathNew,$customer_id));die;
                    $this->Flash->error(__('The attachment could not be deleted. Please, try again.'));
                    
                }
                
            }
            return $this->redirect(['action' => 'edit',encryptVal($dev_id)]);
        }
        return $this->redirect(['action' => 'index']);
    }
    
    public function targetDate(){
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod("post");
        $this->loadComponent('Common');
        $incidentDate = $_REQUEST['incidentDate'];
        
        $dtformat=CustomerCache::read("dateinputformat");
        $un_dev_target_days=CustomerCache::read("un_dev_target_days");
        $un_dev_working_days=CustomerCache::read("un_dev_working_days");
        
        $targetDate=$this->Common->getTargetDate($dtformat,$un_dev_target_days,$un_dev_working_days,$incidentDate);
        $this->set(compact("targetDate","targetDate"));
        $this->set("_serialize",["targetDate","targetDate"]);
        if($this->request->is("ajax")){
            $this->viewBuilder()
            ->setOption('serialize', $this->viewBuilder()->getVar("_serialize"))
            ->setOption('jsonOptions', JSON_FORCE_OBJECT);
        }
    }

public function getdeviationApplicableSteps($dev_id) {
    
    $this->loadComponent('WiseWorks');
    $allSteps=$this->WiseWorks->getAllSteps();
    $appliacableSteps =$this->WiseWorks->getAllStepsForModel($dev_id);
    $method=$this->request->getParam('action');
    
    $this->loadModel('DevStatusMaster');
    $DevStatusMaster=$this->DevStatusMaster->find('all')->order(["id"=>"Asc"])->toArray();
    $dev_statuses = $this->DevStatusMaster->find('list', ['keyField' => 'id','valueField' => 'status'])->toArray();
    $statusMaster = $this->DevStatusMaster->find('all');
    $this->set('dev_statuses', $dev_statuses);
    
    $this->set('appliacableSteps', $appliacableSteps);
    $this->set('statusMaster', $statusMaster);
    $this->set('method', $method);
}


//added by Sonali
public function extendTargetDate(){
    $loggedUserId=$this->request->getSession()->read('Auth')['id'];
   
    if ($this->request->is('post')){
        $data = $this->request->getData();
        $data['created_by']=$loggedUserId;
        $data['modified_by']=$loggedUserId;
        $data['created']=date($this->DateTimeFormat);
        $id = $data['deviation_id'];
        $dev= $this->Deviation->get($id,[
            'fields'=>['id','reference_number','target_date']
        ]);
        $data['previous_target_date']=$dev['target_date'];
        
        if(!$this->Authorization->can($dev,'ExtendTargetDate')){
            $this->Flash->error(__('Unathorized Access', 'Deviation'));
            return $this->redirect(['action' => 'index']);
        }
//         $this->loadModel('DevTargetExtension');
        $devTargetExtension = $this->Deviation->DevTargetExtension->newEmptyEntity();
        $devTargetExtension = $this->Deviation->DevTargetExtension->patchEntity($devTargetExtension, $data);
        if($this->Deviation->DevTargetExtension->save($devTargetExtension)) {
            $this->Flash->success(__("Target extention has been send to approval for Deviation No. {0}",$dev['reference_number']));
            return $this->redirect(['action' => 'index']);
        }else{
            
            $this->Flash->error(__("Target extention could not be send to approval for Deviation No. {0}. Please, try again.",$dev['reference_number']));
            return $this->redirect(['controller'=>'Deviation','action' => 'index']);
        }
        
    }
}

public function approveRejectExtendTargetDate(){
    $loggedUserId=$this->request->getSession()->read('Auth')['id'];
    //$this->loadModel('ChangeTargetExtension');
    if ($this->request->is('post')){
        $data = $this->request->getData();
        $id=$data['id'];
        $devTargetExtension=$this->Deviation->DevTargetExtension->get($id);
        $data['modified_by']=$loggedUserId;
        $data['modified']=date($this->DateTimeFormat);
        $changeId = $data['deviation_id'];
        $data['action_taken_date']=date($this->DateTimeFormat);
        $data['is_active']='N';
        $dev= $this->Deviation->get($changeId,[
            'fields'=>['id','reference_number','target_date']
        ]);
        
        if(!$this->Authorization->can($dev,'ApproveRejectExtendTargetDate')){
            $this->Flash->error(__('Unathorized Access', 'Deviation'));
            return $this->redirect(['action' => 'index']);
        }
        $status="";
        if(isset($data['Reject'])){
            $data['approval_status']=$data['Reject'];
            $status="Rejected";
        }else if(isset($data['Approve'])){
            $data['approval_status']=$data['Approve'];
            $status="Approved";
            $newTargetDate = $devTargetExtension['new_target_date'];
        }
        $devTargetExtension = $this->Deviation->DevTargetExtension->patchEntity($devTargetExtension, $data);
        if ($this->Deviation->DevTargetExtension->save($devTargetExtension)) {
            if(isset($newTargetDate)){
                $this->Deviation->updateAll(["target_date"=>$newTargetDate],["id"=>$devTargetExtension['deviation_id']]);
            }
            $this->Flash->success(__("Target extention has been ".$status." for Deviation No. {0}",$dev['reference_number']));
            return $this->redirect(['action' => 'index']);
        }else{
            $this->Flash->error(__("Target extention could not be approved/rejected for Deviation No. {0}. Please, try again.",$dev['reference_number']));
            return $this->redirect(['action' => 'index']);
        }
    }
}

        public function updateActionPlan(){
            $loggedUserId=$this->request->getSession()->read('Auth')['id'];
            $actionPlanLogsData=[];
            $this->autoRender = false;
            if ($this->request->is('post')){
                $data = $this->request->getData();
                //debug($data);die;
                $dev= $this->Deviation->get($data['dev_id'],[
                    'fields'=>['id','reference_number','target_date']
                ]);
                if(!$this->Authorization->can($dev,'index')){
                    $this->Flash->error(__('Unathorized Access', 'Deviation'));
                    return $this->redirect(['action' => 'index']);
                }
                $updateCondition = array('id' => $data['id'],'dev_id' => $data['dev_id']);
                $this->loadModel('DevActionPlans');
               $update =  $this->DevActionPlans->updateAll(array('status' => $data['status'] ), $updateCondition);
               if ($update) {
                   $this->loadModel('DevActionPlanLog');
                   $DevActionPlanLog = $this->DevActionPlanLog->newEmptyEntity();
                                      
                   $actionPlanLogsData['dev_action_plan_id'] = $data['id'];
                   $actionPlanLogsData['dev_id'] = $data['dev_id'];
                   $actionPlanLogsData['status'] = $data['status'];
                   $actionPlanLogsData['description'] = $data['action_description'];
                   $actionPlanLogsData['modified_by'] = $loggedUserId;
                   
                   $DevActionPlanLog = $this->DevActionPlanLog->patchEntity($DevActionPlanLog, $actionPlanLogsData);
                   $saved = $this->DevActionPlanLog->save($DevActionPlanLog);
                   if ($saved) {
                       $this->Flash->success(__('Action Plan Update Successfully', 'Deviation'));
                       $this->redirect($this->referer());
                   }
                   else 
                   {
                       $this->Flash->error(__('Action Plan not Saved Please Check', 'Deviation'));
                       $this->redirect($this->referer());
                   }
                   
                }
               
            }
        }

        public function getTransPassData($status_step) {
            $isTransPassword = CustomerCache::read("transactional_password_required");
            if ($isTransPassword == 'Y') {
                $transPass  = isset($status_step->tran_password)?$status_step->tran_password:0;
            }
            else {
                $transPass = 0;
            }
            $this->set('transPass',$transPass);
        }
        
        
        //added by shrirang
        
        public function pendingreport()
        {
            $deviationsEntity=$this->Deviation->newEmptyEntity();
            if(!$this->Authorization->can($deviationsEntity,'pendingreport')){
                $this->Flash->error(__('Unathorized Access', 'Deviation'));
                return $this->redirect(['action' => 'index']);
            }
            
            $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
            $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
            $loggedUserId=$this->request->getSession()->read('Auth')['id'];
           
            $customerLocationData=$this->request->getQuery('customer_locations_id');
            $statusData=$this->request->getQuery('status_master_id');
            $createdData=$this->request->getQuery('created');
            $referenceNumberData=$this->request->getQuery('reference_number');
            $fromDate=$this->request->getQuery('from_date');
            $toDate=$this->request->getQuery('to_date');
            $task_name=$this->request->getQuery('task_name');
            $DepartmentID=$this->request->getQuery('departmentID');
            
            $this->loadModel('DevStatusMaster');
            $statusList= $this->DevStatusMaster->find()->select(['id', 'status'])->toArray();
            $statusCount=count($statusList);

            $condition[]["AND"]=["Deviation.customer_id"=>$customer_id,"Deviation.dev_status_master_id <"=>$statusCount];
            if($customerLocationData != ""){ $condition["AND"][]=['OR'=>["Deviation.customer_locations_id"=>$customerLocationData]]; }
            if($statusData != ""){ $condition["AND"][]=['OR'=>["Deviation.dev_status_master_id"=>$statusData]]; }
            //if($createdData != ""){ $condition["AND"][]=['OR'=>["Deviation.created Like '%$createdData%' "]]; }
            if($referenceNumberData != ""){ $condition["AND"][]=['OR'=>["Deviation.reference_number Like '%$referenceNumberData%' "]]; }
            if($task_name != ""){ $condition["AND"][]=['OR'=>["Deviation.title like '%$task_name%'"]]; }
            if($fromDate == null)
            {
                $fromDate = "";
            }
            if($toDate == null)
            {
                $toDate = "";
            }
            if($fromDate != '' && $toDate != ''){
                $condition['AND'][]="date(Deviation.modified) BETWEEN '".$fromDate."' AND '".$toDate."'";
            }
            if($DepartmentID != ""){ $condition["AND"][]=['OR'=>["Deviation.found_at_dept ="=>$DepartmentID]]; }
            
            $this->loadModel('Departments');
            $DepartmentsData= $this->Departments->find("list",['keyField'=>'id','valueField'=>'department'])->where(["customer_id"=>$customer_id,"active"=>1])->toArray();
            
            $this->paginate = [
                "contain" => ['CreatedByUser'=>['fields'=>['userfullname',"departments_id"],'Departments'],
                    'DevInvestigation','DevTargetExtension','Departments',
                    'DevStatusLog'=>["DevStatusChangeBy"=>["fields"=>["userfullname","departments_id"],'Departments'],
                        "DevNextActionBy"=>["fields"=>["userfullname","departments_id"],'Departments'],
                        "sort"=>["DevStatusLog.id"=>"Desc"],
                       // "conditions"=>["DevStatusLog.action_taken != 'Reject'"]
                       ],
                    'CustomerLocations'=>["fields"=>["name"]]],
                "conditions"=>$condition,
                "order"=>["id"=>"desc"]
            ];
            $deviation = $this->paginate($this->Deviation);
           
            $this->loadComponent('WiseWorks');
            foreach($deviation as $key=>$val){
                $nextStatusId= $this->WiseWorks->getNextStep($val->dev_status_master_id,$val['id']);
                $val['next_id'] = $nextStatusId;
            }
            
            $this->loadModel('NotificationInbox');
            
            $cond=array(['AND'=>["NotificationInbox.plugin_name"=>"Dev"]]);
            $NotificationInbox= $this->NotificationInbox->find("all")
            ->where([$cond,"NotificationInbox.customer_id"=>$customer_id])
            ->toArray();
            $this->set(compact('DepartmentID','DepartmentsData','NotificationInbox','task_name','fromDate','toDate','statusList','referenceNumberData','createdData','statusData','customerLocationData','deviation','loggedUserId'));
       
        
            if ($this->request->getQuery('export') != null) {
                
                $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
                $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
                $loggedUserId=$this->request->getSession()->read('Auth')['id'];
                
                $customerLocationData=$this->request->getQuery('customer_locations_id');
                $statusData=$this->request->getQuery('status_master_id');
                $createdData=$this->request->getQuery('created');
                $referenceNumberData=$this->request->getQuery('reference_number');
                $fromDate=$this->request->getQuery('from_date');
                $toDate=$this->request->getQuery('to_date');
                $task_name=$this->request->getQuery('task_name');
                
                $this->loadModel('DevStatusMaster');
                $statusList= $this->DevStatusMaster->find()->select(['id', 'status'])->toArray();
                $statusCount=count($statusList);
                
                $condition[]["AND"]=["Deviation.customer_id"=>$customer_id,"Deviation.dev_status_master_id <"=>$statusCount];
                if($customerLocationData != ""){ $condition["AND"][]=['OR'=>["Deviation.customer_locations_id"=>$customerLocationData]]; }
                if($statusData != ""){ $condition["AND"][]=['OR'=>["Deviation.dev_status_master_id"=>$statusData]]; }
                if($createdData != ""){ $condition["AND"][]=['OR'=>["Deviation.created Like '%$createdData%' "]]; }
                if($referenceNumberData != ""){ $condition["AND"][]=['OR'=>["Deviation.reference_number Like '%$referenceNumberData%' "]]; }
                if($task_name != ""){ $condition["AND"][]=['OR'=>["Deviation.title like '%$task_name%'"]]; }
                
                $DepartmentID=$this->request->getQuery('departmentID');
               
                if($fromDate == null)
                {
                    $fromDate = "";
                }
                if($toDate == null)
                {
                    $toDate = "";
                }
                if($fromDate != '' && $toDate != ''){
                    $condition['AND'][]="date(Deviation.modified) BETWEEN '".$fromDate."' AND '".$toDate."'";
                }
                
                if($DepartmentID != ""){ $condition["AND"][]=['OR'=>["Deviation.found_at_dept ="=>$DepartmentID]]; }
                
                $this->loadModel('Departments');
                $DepartmentsData= $this->Departments->find("list",['keyField'=>'id','valueField'=>'department'])->where(["customer_id"=>$customer_id,"active"=>1])->toArray();
               
                $deviation= $this->Deviation->find("all")
                ->contain(['CreatedByUser'=>['fields'=>['userfullname',"departments_id"],'Departments'],
                    'DevInvestigation','DevTargetExtension','Departments',
                    'DevStatusLog'=>["DevStatusChangeBy"=>["fields"=>["userfullname","departments_id"],'Departments'],
                        "DevNextActionBy"=>["fields"=>["userfullname","departments_id"],'Departments'],
                        "sort"=>["DevStatusLog.id"=>"Desc"],
                        //"conditions"=>["DevStatusLog.action_taken != 'Reject'"]
                        
                    ],
                ])
                ->where([$condition])
                ->order(["Deviation.id"=>"desc"])
                ->toArray();
                
               // debug($deviation);die;
               // $deviation = $this->paginate($this->Deviation);
                
                $this->loadComponent('WiseWorks');
                foreach($deviation as $key=>$val){
                    $nextStatusId= $this->WiseWorks->getNextStep($val->dev_status_master_id,$val['id']);
                    $val['next_id'] = $nextStatusId;
                }
                
                $this->loadModel('NotificationInbox');
                
                $cond=array(['AND'=>["NotificationInbox.plugin_name"=>"Dev"]]);
                $NotificationInbox= $this->NotificationInbox->find("all")
                ->where([$cond,"NotificationInbox.customer_id"=>$customer_id])
                ->toArray();
                
                $conn = $this;
                $CallBackStream = new CallbackStream(function () use ($conn,$deviation,$statusList,$NotificationInbox) {
                    try {
                        $conn->viewBuilder()->setLayout("xls");
                        $conn->set(compact('deviation','statusList'));
                        echo $conn->render('pendingreportexcel');
                    } catch (Exception $e) {
                        echo $e->getMessage();
                        $e->getTrace();
                    }
                });
                    return $this->response->withBody($CallBackStream)
                    ->withAddedHeader("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
                    ->withAddedHeader("Content-disposition", "attachment; filename=Deviation In Process Report.xls");
                    
            }
       
        }
        //Ended here by shrirang
        
        //Added by shrirang
        
        public function devtaskreport()
        { 
            $deviationX = $this->Deviation->newEmptyEntity();
            if(!$this->Authorization->can($deviationX,'devtaskreport')){
                $this->Flash->error(__('You are not allowed to access Deviation Module'));
                return $this->redirect(["plugin"=>false,"controller"=>"pages",'action' => 'home']);
            }
            $tasktypevalue=$this->request->getQuery('tasktype');
            $cust_id=$this->request->getSession()->read('Auth')['customer_id'];
            $user_id=$this->request->getSession()->read('Auth')['id'];
            $this->loadModel('Dev.DevActionPlans');
            $query=$this->request->getQuery("table_search");
            
            $conditions=[];
            $condition=[];
            $fromDate=$this->request->getQuery('from_date');
            $toDate=$this->request->getQuery('to_date');
            $task_name=$this->request->getQuery('task_name');
            $referenceNumberData=$this->request->getQuery('reference_number');
            $tasknumber=$this->request->getQuery('cr_tasknumber');
            $DepartmentID=$this->request->getQuery('departmentID');
            if($fromDate == null)
            {
                $fromDate = "";
            }
            if($toDate == null)
            {
                $toDate = "";
            }
            if($fromDate != '' && $toDate != ''){
                $condition['AND'][]="date(DevActionPlans.planned_start_date) >='".$fromDate."'";
            }
            
            if($fromDate != '' && $toDate != ''){
                $condition['AND'][]="date(DevActionPlans.planned_end_date) <='".$toDate."'";
            }
            
            
            if($referenceNumberData != ""){ $condition["AND"][]=['OR'=>["Deviation.reference_number Like '%$referenceNumberData%' "]]; }
            
            if($task_name != ""){ $condition["AND"][]=["DevActionPlans.title like '%$task_name%'"]; }
            
            if ($DepartmentID != null && trim($DepartmentID, " ") != "") {
                $condition['AND'][] = "departments_id = $DepartmentID";
            }
           
                $conditions=["Deviation.customer_id"=>$cust_id,"(DevActionPlans.status NOT IN('Close','Complete','Pending') OR DevActionPlans.status IS NULL)","(DevActionPlans.action_for IN('Implement') OR DevActionPlans.action_for IN('ImplementPlan'))"];
            
            if($query!=null && trim($query," ")!=""){
                $conditions["or"][]="Deviation.reference_number like '%$query%'";
                $conditions["or"][]="DevActionPlans.title like '%$query%'";
                $conditions["or"][]="DevActionPlans.description like '%$query%'";
            }
            $this->paginate = [
                "conditions"=>[$conditions,$condition],
                "contain"=>["DevActionPlanLog"=>['Action_Modified_by'=>['Departments']],
                    "Deviation",
                    "AssignedTo"=>['Departments']
                ],
                "order"=>["id"=>"desc"]
            ];
            $devActionPlans = $this->paginate($this->DevActionPlans);
            $this->loadModel('Departments');
            $DepartmentsData= $this->Departments->find("list",['keyField'=>'id','valueField'=>'department'])->where(["customer_id"=>$cust_id,"active"=>1])->toArray();
            
            $this->set(compact('DepartmentsData','tasktypevalue','query','task_name','DepartmentID','referenceNumberData','fromDate','toDate'));
            $this->set('devActionPlans', $devActionPlans);
            
            if ($this->request->getQuery('export') != null) {
                
                $tasktypevalue=$this->request->getQuery('tasktype');
                $cust_id=$this->request->getSession()->read('Auth')['customer_id'];
                $user_id=$this->request->getSession()->read('Auth')['id'];
                $this->loadModel('Dev.DevActionPlans');
                $query=$this->request->getQuery("table_search");
                
                $conditions=[];
                $condition=[];
                $fromDate=$this->request->getQuery('from_date');
                $toDate=$this->request->getQuery('to_date');
                $task_name=$this->request->getQuery('task_name');
                $referenceNumberData=$this->request->getQuery('reference_number');
                $tasknumber=$this->request->getQuery('cr_tasknumber');
                $DepartmentID=$this->request->getQuery('departmentID');
                if($fromDate == null)
                {
                    $fromDate = "";
                }
                if($toDate == null)
                {
                    $toDate = "";
                }
                if($fromDate != '' && $toDate != ''){
                    $condition['AND'][]="date(DevActionPlans.planned_start_date) >='".$fromDate."'";
                }
                
                if($fromDate != '' && $toDate != ''){
                    $condition['AND'][]="date(DevActionPlans.planned_end_date) <='".$toDate."'";
                }
                
                
                if($referenceNumberData != ""){ $condition["AND"][]=['OR'=>["Deviation.reference_number Like '%$referenceNumberData%' "]]; }
                
                if($task_name != ""){ $condition["AND"][]=["DevActionPlans.title like '%$task_name%'"]; }
                
                if ($DepartmentID != null && trim($DepartmentID, " ") != "") {
                    $condition['AND'][] = "departments_id = $DepartmentID";
                }
                
                $conditions=["Deviation.customer_id"=>$cust_id,"(DevActionPlans.status NOT IN('Close','Complete','Pending') OR DevActionPlans.status IS NULL)","(DevActionPlans.action_for IN('Implement') OR DevActionPlans.action_for IN('ImplementPlan'))"];
                
                $devActionPlans= $this->DevActionPlans->find("all")
                ->contain (
                    ["DevActionPlanLog"=>['Action_Modified_by'=>['Departments']],
                        "Deviation",
                        "AssignedTo"=>['Departments']
                    ])
                    ->where([$conditions,$condition])
                    ->order(["Deviation.id"=>"desc"])
                    ->toArray();
                    
                    $this->loadModel('Departments');
                    $DepartmentsData= $this->Departments->find("list",['keyField'=>'id','valueField'=>'department'])->where(["customer_id"=>$cust_id,"active"=>1])->toArray();
                    
                    $conn = $this;
                    $CallBackStream = new CallbackStream(function () use ($conn,$devActionPlans) {
                        try {
                            $conn->viewBuilder()->setLayout("xls");
                            $conn->set(compact('devActionPlans'));
                            echo $conn->render('devtaskreportexport');
                        } catch (Exception $e) {
                            echo $e->getMessage();
                            $e->getTrace();
                        }
                    });
                        return $this->response->withBody($CallBackStream)
                        ->withAddedHeader("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
                        ->withAddedHeader("Content-disposition", "attachment; filename= Devition Task In Process Report.xls");
                        
            }
        }
        
        public function reviewdh($id = null, $currentStatusId=null, $nextStatusId=null, $prevStatusId=null)
        {
            $id=($id==null)?null:decryptVal($id);
            $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
            $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
            $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
            $plugin=$this->request->getParam('plugin');
            $controller=$this->request->getParam('controller');
            $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
            $loggedUserDeptId=$this->request->getSession()->read('Auth')['departments_id'];
            $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
            $statusData=array("current_id"=>$currentStatusId,"next_id"=>$nextStatusId,"prev_id"=>$prevStatusId);
            $deviation_data = $this->Deviation->get($id, [
                'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                    'DevStatusLog'=>['DevNextActionBy'=>['fields'=>['userfullname']]],
                    'DevOwner'=>['fields'=>['userfullname']],
                ],
            ]);
            $isplanned='';
            if($deviation_data['isplanned']==2){
                $isplanned="un";
            }
            $this->getdeviationApplicableSteps($id);
            $customer_id=$deviation_data['customer_id'];
            $loggedUserId=$this->request->getSession()->read('Auth')['id'];
            $lastrecord=!empty($deviation_data['dev_status_log'])?count($deviation_data['dev_status_log']):0;
            $verifier=($lastrecord>0)?!empty($deviation_data['dev_status_log'][$lastrecord-1]['DevNextActionBy'])?$deviation_data['dev_status_log'][$lastrecord-1]['DevNextActionBy']['userfullname']:'':'';
            $dev_owner=!empty($deviation_data['DevOwner'])?$deviation_data['DevOwner']['userfullname']:'';
            $deviation_data->previousStepId = $prevStatusId ;
            if(!$this->Authorization->can($deviation_data, 'approveqa')){
                    $this->Flash->error(__('Only '.$verifier.' (Owner) have permission to approve deviation.'));
                return $this->redirect(['action' => 'index']);
            }
            
            //added by shrirang
            $this->loadComponent('WiseWorks');
            $allSteps=$this->WiseWorks->getAllSteps();
            $status_step = $this->WiseWorks->getSingleStep($currentStatusId,$allSteps);//debug($deviation_data);
//             if($deviation_data->action_taken_immediate){
//                 $status_step = $this->WiseWorks->getSingleStep($currentStatusId,$allSteps);//debug($status_step);
//             }
//             else{
//                 $status_step = $this->WiseWorks->getSingleStep($status_step->next_step_status_master_id,$allSteps);//debug($status_step);die;
//                 $nextStatusId=$status_step->next_step_status_master_id;
//             }//debug($status_step);
            
            $this->getTransPassData($status_step);
            //ended here
            
            if ($this->request->is(['patch', 'post', 'put'])) {
                $requestData=$this->request->getData();//debug($requestData);die;
                $dev_status_log=$requestData['dev_status_log'];
                if(isset($requestData['submitApprove'])){
                    $requestData['dev_status_master_id']=$currentStatusId;
                }
                
                $this->loadComponent('Common');
                $this->Common->close_notification($plugin,'Deviation',$id);
                
                $deviation_data = $this->Deviation->patchEntity($deviation_data, $requestData);
                if ($result=$this->Deviation->save($deviation_data)) {
                    $dev_id=$id;
                    $status=$dev_status_log['action_taken'];
                    if(isset($requestData['submitApprove'])){
                        if(!empty($dev_status_log)){
                            $dev_status_log['step_complete']=1;
                            
                            $status_log = $this->Deviation->DevStatusLog->newEmptyEntity();
                            $status_log=$this->Deviation->DevStatusLog->patchEntity($status_log,$dev_status_log);
                            $this->Deviation->DevStatusLog->save($status_log);
                            $this->loadModel('DevActionPlans');
                            $this->DevActionPlans->updateAll(array('status' => 'Open'), array('dev_id' => $dev_id));
                        }
                    }
                    
                    $dev_status=$this->loadModel('DevStatusMaster');
                    $nextstatus= $dev_status->find()->select(['status'])->where(['id =' => $nextStatusId])->first();//debug($nextstatus);die;
                    $nextstatus=$nextstatus->status;
                    
                    if(isset($requestData['submitApprove'])){
                        
                        //added by shrirang
                        
                        $prevStatusId =$currentStatusId;
                        $current_status_id = $nextStatusId;
                        $nextStatussId = $this->WiseWorks->getNextStep($nextStatusId,$dev_id);
                        $title = "Deviation ".$result->reference_number." QA Approval done";
                        $notificationData = [
                            'selected_users' => $result->created_by,
                            'reference_number' =>$result->reference_number,
                            'title' =>$title,
                            'category'=>$result->classification,
                            'against'=>$result->against,
                            'target_date'=>$result->target_date,
                            'customer_id'=>$customer_id,
                            'created_by'=>$loggedUserId,
                            'notification_identifier'=>'qaApproval_done',
                            'id' =>$dev_id,
                            'current_status_id'=>$current_status_id,
                            'nextStatusId'=>$nextStatussId,
                            'prevStatusId'=>$prevStatusId
                        ];
                        //debug($nextStatussId);die;
                        $this->loadComponent('CommonMailSending');
                        $this->CommonMailSending->selected_email_details($customer_id,$customer_location_id,$loggedUserId,$notificationData);
                        $this->loadComponent('QmsNotification');
                        $this->QmsNotification->selected_notificaion_details($plugin,'DevInvestigation',$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                        //Ended by here by shrirang
                    }
                    if(isset($requestData['submitReject'])){
                        $this->loadModel('DevStatusLog');
                        $this->loadModel('DevStatusMaster');
                        $lastuser= $this->DevStatusLog->find()->select(['action_by'])->where(['dev_id =' => $dev_id,"dev_status_master_id"=>$prevStatusId])->first();
                        $lastuserid=$lastuser->action_by;
                        $rejectComment =$dev_status_log['next_action_by_comments'];
                        $laststep= $this->DevStatusMaster->find()->select(['form_name','controller_name'])->where(["id"=>$prevStatusId])->first();
                        $controller=$laststep->controller_name;
                        $action=$laststep->form_name;
                        
                        //added by shrirang
                        $nextStatussId =$currentStatusId;
                        $current_status_id = $this->WiseWorks->getPreviousStep($nextStatussId,$dev_id);
                        $prevsStatusId = $this->WiseWorks->getPreviousStep($current_status_id,$dev_id);
                        
                        $title = "Deviation ".$result->reference_number." QA Approval rejected";
                        $notificationData = [
                            'selected_users' => $lastuserid,
                            'reference_number' =>$result->reference_number,
                            'title' =>$title,
                            'category'=>$result->classification,
                            'against'=>$result->against,
                            'target_date'=>$result->target_date,
                            'customer_id'=>$customer_id,
                            'created_by'=>$loggedUserId,
                            'notification_identifier'=>'qaApproval_rejected',
                            'id' =>$dev_id,
                            'current_status_id'=>$current_status_id,
                            'nextStatusId'=>$nextStatussId,
                            'prevStatusId'=>$prevsStatusId
                        ];
                        
                        $this->loadComponent('CommonMailSending');
                        $this->CommonMailSending->selected_email_details($customer_id,$customer_location_id,$loggedUserId,$notificationData);
                        $this->loadComponent('QmsNotification');
                        $this->QmsNotification->selected_notificaion_details($plugin,$controller,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                        //Ended by here by shrirang
                        //return $this->redirect(['action' => 'rejectstatus',$dev_id,$prevStatusId,$currentStatusId]);
                        $this->rejectstatus($dev_id,$prevStatusId,$currentStatusId,$rejectComment);
                    }
                    $this->Flash->success(__('The deviation has been saved.'));
                    
                    return $this->redirect(['action' => 'index']);
                }
                $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
            }
            $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$currentStatusId,'DevStatusLog.action_taken !='=>'Reject',])->last();
            $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$prevStatusId])->last();
            
            $this->set(compact('currentLog','preLog', 'currentStatusId', 'nextStatusId', 'prevStatusId','statusData','status_step'));
            $this->getDeviationData($id,true);
            $this->viewBuilder()->setTemplate("reviewdh");
            $this->set('DateTimeFormat',$this->DateTimeFormat); //for passing variable to template
        }
        
        //Ended here by shrirang
    

        
        
        public function getDeviation(){
            $this->Authorization->skipAuthorization();
            $this->autoRender=false;
            $text=$this->request->getData('text');
            $customer_id=$this->request->getData('customerid');
            $complaint1=$this->Deviation->find('all',['contain'=>['ProductionLineMaster'],'conditions'=>["Deviation.title like '%$text%'",'Deviation.customer_id'=>$customer_id]]);
            
            $allComplaint=$complaint1->map(function($value,$key){
                return [
                    'id'=>$value->id,
                    'value'=>encryptVal($value->id),
                    'text'=>$value->title,
                    'refno'=>$value->reference_number,
                    'product'=>!empty($value->production_line_master)?$value->production_line_master->prod_line:'N/A',
                    'date'=>!empty($value->created)?$value->fcreated:''
                ];
            });
                $this->response=$this->response->withType('application/json')
                ->withStringBody(json_encode($allComplaint));
                return null;
        }

        //Customer Approval By raj Sd

        public function customerApproval($id = null,$currentStatusId=null, $nextStatusId=null, $prevStatusId=null,$optionalRejetStatusId=null)
        {;
            if($this->request->is('ajax')){
                $data = $this->request->getData();
                
                $http = new Client();
    
                // $url = Router::url(['controller'=>'Pdf', 'action'=>'generatepdf',encryptVal($data['dev_customer_approval']['model_id']),encryptVal("getChangeRequestData") ]);
                // Debug or use the generated URL
                // $response = $http->get($url);
            $deviation = $this->Deviation->find('all', ['conditions' => array('Deviation.id' => $data['dev_customer_approval']['model_id'])])->first();
                $fileName =  encryptVal($data['dev_customer_approval']['model_id'])."_Deviation.pdf";
            
                $filePath = QMSFile::getFilePath("deviation",  $data['dev_customer_approval']['customer_id']);
                $pdf_file = QMSFile::fileExistscheck("deviation".DS.$fileName, $data['dev_customer_approval']['customer_id']);
                if($pdf_file){
                    $pdfContent  = base64_encode(file_get_contents($filePath.DS.$fileName));
                    $attachments = [$deviation->reference_number.".pdf" => ['file' => $filePath.DS.$fileName,
                    'mimetype' => 'application/pdf'
                ]];
                    $emailData = array(
                        "customer_id"=> $data['dev_customer_approval']['customer_id'],
                        "description" => $data['dev_customer_approval']['description'],
                        "email" => $data['dev_customer_approval']['customer_email'],
                        "subject" => 'Customer Approval for '.$deviation->reference_number,
                        "attachments" =>$attachments,
                        "header"=>"Customer Approval for '.$deviation->reference_number",
                        "pdf_name" => $deviation->reference_number.".pdf"
                    );
    
                $this->loadComponent("CommonMailSending");
                if ($this->CommonMailSending->simpleMail($data['dev_customer_approval']['customer_id'], $emailData)){
                    $this->loadModel("DevCustomerApproval");
                    $data['dev_customer_approval']['active'] = 1;
                    $cAentity = $this->DevCustomerApproval->newEmptyEntity();
                    $cApatchentity = $this->DevCustomerApproval->patchEntity($cAentity, $data['dev_customer_approval']);
                    $cAentity = $this->DevCustomerApproval->save($cApatchentity);
                }
                echo $this->Flash->success('Email sent succesfully.');
                return;
            }else{
                echo $this->Flash->error('File not found ');
                return ;
            }
            }
            $id=($id==null)?null:decryptVal($id);
            $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
            $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
            $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
            $plugin = $this->request->getParam("plugin");
            $plugin = strtolower($plugin);
            $statusData=array("current_id"=>$currentStatusId,"next_id"=>$nextStatusId,"prev_id"=>$prevStatusId);
            $deviation_data = $this->Deviation->get($id, [
                'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                    'DevStatusLog'=>['DevNextActionBy'=>['fields'=>['userfullname']]],
                    "DevOwner"=>['fields'=>['userfullname']],'DevActionPlans'
                ],
            ]);
            $isplanned='';
            if($deviation_data['isplanned']==2){
                $isplanned="un";
            }
            $customer_id=$this->request->getSession()->read('Auth')['customer_id'];
            $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
            $loggedUserId=$this->request->getSession()->read('Auth')['id'];
            
            $loggedUserDeptId=$this->request->getSession()->read('Auth')['departments_id'];
            $plugin=$this->request->getParam('plugin');
            $controller=$this->request->getParam('controller');
            $method=$this->request->getParam('action');
            
            $this->loadComponent('Common');
            //$createdBy=!empty($deviation_data['CreatedByUser'])?$deviation_data['CreatedByUser']['userfullname']:'';
            //$lastrecord=!empty($deviation_data['dev_status_log'])?count($deviation_data['dev_status_log']):0;
            $verifier=!empty($deviation_data['DevOwner'])?$deviation_data['DevOwner']['userfullname']:'';
            
            if(!$this->Authorization->can($deviation_data, 'customerApproval')){
                $this->Flash->error(__('Only '.$verifier.' (Owner) have permission to verify deviation.'));
                return $this->redirect(['action' => 'index']);
            }
            $this->getdeviationApplicableSteps($id);
            
            //added by shrirang
            $this->loadModel("Dev.DevStatusLog");
            $this->loadComponent('WiseWorks');
            $allSteps=$this->WiseWorks->getAllSteps();
            $status_step = $this->WiseWorks->getSingleStep($currentStatusId,$allSteps);
            $prevStatusIds = $this->WiseWorks->getPreviousStep($currentStatusId,$id);
            $preLogs = $this->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$prevStatusIds,"DevStatusLog.step_complete"=>1])->last();
            $nextElementName = array();
            foreach ($allSteps as $step){
                if($step['status_master_id']==$currentStatusId){
                    $nextElementName = (object)$step;
                }
            }
            $this->getTransPassData($status_step);
            //ended here
            if ($this->request->is(['patch', 'post', 'put'])) {

                $saveData=$this->request->getData();
                if(!empty($saveData['dev_customer_approval'])){
                    $this->loadModel('DevCustomerApproval');
                    if (isset($saveData['dev_customer_approval']['attachment_file'])) {
                        $attachment = $saveData['dev_customer_approval']['attachment_file'];
                        
                        if ($attachment->getError() == 0) {
                            $attachment_name = time().$attachment->getClientFilename();
     
                            $tmp_name = $attachment->getStream()->getMetadata('uri');
                            $filesPathNew = "dev_customer_approval" . DS . $attachment_name;
                            QMSFile::moveUploadedFile($tmp_name, $filesPathNew, $customer_id);                         
                            $saveData['dev_customer_approval']['attachment_file']= $attachment_name;
                        }
                        if($attachment->getClientFilename() == ''){
                            $saveData['dev_customer_approval']['attachment_file']= '';
    
                        }
                        
                        if($saveData['dev_customer_approval']['id']==''){
                            $CustomerApproval = $this->DevCustomerApproval->newEmptyEntity();
                        }else{
                            $CustomerApproval = $this->DevCustomerApproval->get($saveData['dev_customer_approval']['id']);
                        }
                        $CustomerApproval = $this->DevCustomerApproval->patchEntity($CustomerApproval, $saveData['dev_customer_approval']);
    
    
                        $this->DevCustomerApproval->save($CustomerApproval);
    
                    }
    
                }
                $saveData['dev_status_master_id']=$currentStatusId;
                if(isset($saveData['submitToNext'])){
                    $saveData['dev_status_log'][0]['action_taken']="Approve";
                    $saveData['dev_status_log'][0]['step_complete']=1;
                }else{
                    $saveData['dev_status_log'][0]['step_complete']=0;
                    $saveData['dev_status_log'][0]['action_taken']="submit";
                }
                // DEBUG($saveData);exit;
                // , [
                //     'associated' => ['ChangeStatusLog']
                // ]
                // unset($saveData['change_status_log']);
                $this->loadModel("DevStatusLog");
                $check = $this->DevStatusLog->find('all', [
                    'conditions' => array('dev_status_master_id' => $saveData["dev_status_log"][0]['dev_status_master_id'], 'dev_id'=>$saveData["dev_status_log"][0]['dev_id'])
                ])->first();
                if(!$check){
                    $check = $this->DevStatusLog->newEmptyEntity();
                }
                $changeRequest = $this->DevStatusLog->patchEntity($check, $saveData["dev_status_log"][0]);
                $result = $this->DevStatusLog->save($changeRequest);
                $this->Deviation->updateAll(["dev_status_master_id"=>$saveData["dev_status_log"][0]['dev_status_master_id']],['id'=>$saveData["dev_status_log"][0]['dev_id']]);
    
              if($result){
                $this->Flash->success(__('The Deviation Changes has been saved.'));
                return $this->redirect(['action' => 'index']);
              }
                $this->Flash->error(__('The Deviation Changes could not be saved. Please, try again.'));
            }
            $change_status=$this->loadModel('DevStatusMaster');
            $nextstatus= $change_status->find()->select(['status'])->where(['id =' => $nextStatusId])->first();
            $nextstatus=$nextstatus->status;
            $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$currentStatusId])->last();
            $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$prevStatusId])->last();
             $userCondition=array("Users.customer_id"=>$customer_id,"Users.active"=>1);
            $this->loadModel("Users");
            $nextApprovalList = $this->Users->find('list', ['keyField' => 'id','valueField' => 'full_name'])->where($userCondition)->toArray();
            $this->loadModel("Users");
            $approversCondition=array("plugin"=>$plugin,"controller"=>$controller,"element_name"=>"cqa_approve","is_auth"=>1,'RolewisePermissions.can_approve'=>1,'CustomerRoles.customer_id'=>$customer_id);
            $approvers=$this->Common->getAuthList($approversCondition,$loggedUserDeptId);
            $this->loadModel('DevAttachments');
            $condition_file=array(
                "dev_id"=>$id,
                "doc_step_attachment"=>'investigate_doc'
            );
            $this->loadModel("capa");
            $capa= $this->capa->find("all")
            ->where(["plugin"=>"Deviation","model_reference_id"=>$deviation_data['id']])
            ->first();
            
            $investigateAttachmentfile = $this->DevAttachments->find("all",["conditions"=>$condition_file])->toArray();
            $this->set('investigateAttachmentfile',$investigateAttachmentfile);
            $this->set(compact('statusData','approvers','currentLog','preLog','nextApprovalList', "nextElementName","plugin", "nextstatus",'capa'));
            $this->getDeviationData($id,true);
            $this->set('DateTimeFormat',$this->DateTimeFormat);
             //for passing variable to template
    }
    
    public function audittrailreview($id = null, $currentStatusId=null, $nextStatusId=null, $prevStatusId=null,$optionalRejetStatusId=null)
    {
        $id=($id==null)?null:decryptVal($id);
        $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
        $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
        $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
        $optionalRejetStatusId=($optionalRejetStatusId==null)?null:decryptVal($optionalRejetStatusId);
        $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
        $plugin=$this->request->getParam('plugin');
        $controller=$this->request->getParam('controller');
        $method=$this->request->getParam('action');
        $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
        $deviation_data = $this->Deviation->get($id, [
            'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                'DevStatusLog',"DevAccess",'DevInvestigation','DevAssessment'
            ],
        ]);
        $isplanned='';
        if($deviation_data['isplanned']==2){
            $isplanned="un";
        }
        $this->getdeviationApplicableSteps($id);
        $customer_id=$deviation_data['customer_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        $isInvestigationCompleted=0;$isActionPlanCompleted=0;$isCQA=0;
       
        $deviation_data->previousStepId = $prevStatusId ;
        
        
        
        
        
        if(!$this->Authorization->can($deviation_data, 'audittrailreview')){
           
            $this->Flash->error(__('Only selected Department Head Members have permission to approve deviation.'));
            return $this->redirect(['action' => 'index']);
        }
       
        $this->loadComponent('WiseWorks');
        $allSteps=$this->WiseWorks->getAllSteps();
        $status_step = $this->WiseWorks->getSingleStep($currentStatusId,$allSteps);
        $this->getTransPassData($status_step);
        $nextElementName=$this->WiseWorks->getSingleStep($currentStatusId,$allSteps);
        $nextStepName=$this->WiseWorks->getSingleStep($nextElementName->next_step_status_master_id,$allSteps);
        
        if ($this->request->is(['patch', 'post', 'put'])) {
            $requestData=$this->request->getData();//debug($requestData);die;
            $dev_status_log=$requestData['dev_status_log'];
            if(isset($requestData['submitApprove'])){
                $requestData['dev_status_master_id']=$currentStatusId;
            }
            $deviation_data = $this->Deviation->patchEntity($deviation_data, $requestData);

            if ($result=$this->Deviation->save($deviation_data)) {
                $dev_id=$id;
                $status=$dev_status_log['action_taken'];
                if(isset($requestData['submitApprove'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                        
                        $status_log = $this->Deviation->DevStatusLog->newEmptyEntity();
                        $status_log=$this->Deviation->DevStatusLog->patchEntity($status_log,$dev_status_log);
                        $log =  $this->Deviation->DevStatusLog->save($status_log);
                    }
                   
                    $nextStatussId = $this->WiseWorks->getNextStep($nextStatusId,$dev_id);
                    $title = "Deviation ".$result->reference_number." Investigate Approval done";
                    $notificationData = [
                        'reference_number' =>$result->reference_number,
                        'title' =>$title,
                        'category'=>$result->dev_investigation[0]['final_classification'],
                        'against'=>$result->against,
                        'target_date'=>$result->target_date,
                        'customer_id'=>$customer_id,
                        'created_by'=>$loggedUserId,
                        'notification_identifier'=>'audittrailreview',
                        'id' =>$dev_id,
                        'current_status_id'=>$nextStatusId,
                        'nextStatusId'=>$nextStatussId,
                        'prevStatusId'=>$currentStatusId
                    ];
                    
                    $this->loadComponent('CommonMailSending');
                    $this->CommonMailSending->email_details($plugin,$controller,$method,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    $this->loadComponent('QmsNotification');
                    $this->QmsNotification->notificaion_details($plugin,$controller,$method,$customer_id,$customer_location_id,$loggedUserId,$notificationData,$loggedUserEmailId);
                    
                }
                
                $dev_status=$this->loadModel('DevStatusMaster');
                $nextstatus= $dev_status->find()->select(['status'])->where(['id =' => $nextStatusId])->first();
                $nextstatus=$nextstatus->status;
                
                if(isset($requestData['submitReject'])){//debug($deviation_data);
                    return $this->redirect(['action' => 'rejectstatus',$dev_id,$optionalRejetStatusId,$currentStatusId]);
                    
                    $this->loadModel('DevStatusLog');
                    $this->loadModel('DevStatusMaster');
                    $lastuser= $this->DevStatusLog->find()->select(['action_by'])->where(['dev_id =' => $dev_id,"dev_status_master_id"=>$prevStatusId])->first();
                    $lastuserid=$lastuser->action_by;
                    
                    $laststep= $this->DevStatusMaster->find()->select(['form_name','controller_name'])->where(["id"=>$prevStatusId])->first();
                    $controller=$laststep->controller_name;
                    $action=$laststep->form_name;
                    
                    $title = "Your Request of Deviation ".$deviation_data['reference_number']." is Rejected by ".$this->request->getSession()->read('Auth.first_name')." ".$this->request->getSession()->read('Auth.last_name');
                    
                    $notification=new SimpleNotification([
                        "notification_inbox_data"=>[
                            "customer_id"=>$lastuserid,
                            "created_by"=>$loggedUserId,
                            "user_type"=>"Users",   // accepts User|Groups|Departments
                            "user_reference_id"=>$deviation_data['reported_by'], // relavtive id
                            "title"=>$title, // title of notification
                            "comments"=>"Deviation Step Rejected ", // content of notification
                            "plugin_name"=>"Dev", // for which plugin_name you are highlighting. if required
                            "model_reference_name"=>"Deviation", // for which plugin reference name   if required
                            "model_reference_id"=>$dev_id, //   if required
                            "action_link"=>["plugin"=>"Dev", "controller"=>$controller,"action"=>$action, $dev_id], // link to redirect to user.
                            "type"=>"Action",
                        ],
                    ]);
                    $notification->send();
                    
                }
                $this->Flash->success(__('The deviation has been saved.'));
                
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$currentStatusId,'DevStatusLog.action_taken !='=>'Reject',])->last();
        $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$prevStatusId])->last();
        
        
        $this->set(compact('currentLog','preLog', 'currentStatusId', 'nextStatusId', 'prevStatusId','nextStepName'));
        $this->getDeviationData($id,true);
        $this->viewBuilder()->setTemplate("audittrailreview");
        $this->set('DateTimeFormat',$this->DateTimeFormat); //for passing variable to template
    }
    public function deptheadreview($id = null, $currentStatusId=null, $nextStatusId=null, $prevStatusId=null)
    {
        $id=($id==null)?null:decryptVal($id);
        $currentStatusId=($currentStatusId==null)?null:decryptVal($currentStatusId);
        $nextStatusId=($nextStatusId==null)?null:decryptVal($nextStatusId);
        $prevStatusId=($prevStatusId==null)?null:decryptVal($prevStatusId);
        $plugin=$this->request->getParam('plugin');
        $controller=$this->request->getParam('controller');
        $loggedUserEmailId= $this->request->getSession()->read('Auth')['email'];
        $loggedUserDeptId=$this->request->getSession()->read('Auth')['departments_id'];
        $customer_location_id=$this->request->getSession()->read('Auth')['base_location_id'];
        $statusData=array("current_id"=>$currentStatusId,"next_id"=>$nextStatusId,"prev_id"=>$prevStatusId);
        $deviation_data = $this->Deviation->get($id, [
            'contain' => ['CreatedByUser'=>['fields'=>['userfullname']],
                'DevStatusLog'=>['DevNextActionBy'=>['fields'=>['userfullname']]],
                'DevOwner'=>['fields'=>['userfullname']],
            ],
        ]);
        
        $isplanned='';
        if($deviation_data['isplanned']==2){
            $isplanned="un";
        }
        $this->getdeviationApplicableSteps($id);
        $customer_id=$deviation_data['customer_id'];
        $loggedUserId=$this->request->getSession()->read('Auth')['id'];
        $lastrecord=!empty($deviation_data['dev_status_log'])?count($deviation_data['dev_status_log']):0;
        $verifier=($lastrecord>0)?!empty($deviation_data['dev_status_log'][$lastrecord-1]['DevNextActionBy'])?$deviation_data['dev_status_log'][$lastrecord-1]['DevNextActionBy']['userfullname']:'':'';
        $dev_owner=!empty($deviation_data['DevOwner'])?$deviation_data['DevOwner']['userfullname']:'';
        $deviation_data->previousStepId = $prevStatusId ;
        $deviation_data->nextStepId = $nextStatusId ;
        if(!$this->Authorization->can($deviation_data, 'deptheadreview')){
            if($deviation_data['dev_status_master_id'] >= $currentStatusId){
                $this->Flash->error(__('Deviation is already approved.'));
            }else{
                $this->Flash->error(__('Only '.$verifier.' (Owner) have permission to approve deviation.'));
            }
            return $this->redirect(['action' => 'index']);
        }
        
        $this->loadComponent('WiseWorks');
        $allSteps=$this->WiseWorks->getAllSteps();
        $status_step = $this->WiseWorks->getSingleStep($currentStatusId,$allSteps);//debug($deviation_data);
        
        $this->getTransPassData($status_step);
        
        if ($this->request->is(['patch', 'post', 'put'])) {
            $requestData=$this->request->getData();
            $duplicate_deviation=$requestData['duplicate_deviation'];
            
            $saveDatas = array();
            if($duplicate_deviation != ''){
                $duplicate_deviation=json_decode($duplicate_deviation);
                $this->loadModel('DeviationDuplicate');
                foreach($duplicate_deviation as $k=>$row){
                    $saveDatas['deviation_id'] = $id;
                    $saveDatas['duplicate_devation_id'] = $row;
                    $saveDatas['comment'] = $requestData['comment'];
                    $duplocateData = $this->DeviationDuplicate->newEmptyEntity();
                    $duplocateData=$this->DeviationDuplicate->patchEntity($duplocateData,$saveDatas);
                    $res = $this->DeviationDuplicate->save($duplocateData);
                }
                 
            }
            
            
            $dev_status_log=$requestData['dev_status_log'];
            if(isset($requestData['submitApprove'])){
                $requestData['dev_status_master_id']=$currentStatusId;
            }
            
            $this->loadComponent('Common');
            $this->Common->close_notification($plugin,'Deviation',$id);
            $deviation_data = $this->Deviation->patchEntity($deviation_data, $requestData);
            
            if ($result=$this->Deviation->save($deviation_data)) {
                $dev_id=$id;
                $status=$dev_status_log['action_taken'];
                if(isset($requestData['submitApprove'])){
                    if(!empty($dev_status_log)){
                        $dev_status_log['step_complete']=1;
                        
                        $status_log = $this->Deviation->DevStatusLog->newEmptyEntity();
                        $status_log=$this->Deviation->DevStatusLog->patchEntity($status_log,$dev_status_log);
                        $this->Deviation->DevStatusLog->save($status_log);
                        $this->loadModel('DevActionPlans');
                        $this->DevActionPlans->updateAll(array('status' => 'Open'), array('dev_id' => $dev_id));
                    }
                }
                
                $dev_status=$this->loadModel('DevStatusMaster');
                $nextstatus= $dev_status->find()->select(['status'])->where(['id =' => $nextStatusId])->first();
                $nextstatus=$nextstatus->status;
                
                if(isset($requestData['submitApprove'])){
                    
                    //added by shrirang
                    $prevStatusId =$currentStatusId;
                    $current_status_id = $nextStatusId;
                    $nextStatussId = $this->WiseWorks->getNextStep($nextStatusId,$dev_id);
                    $title = "Deviation ".$result->reference_number." QA Approval done";
                    
                }
                if(isset($requestData['submitReject'])){
                    $this->loadModel('DevStatusLog');
                    $this->loadModel('DevStatusMaster');
                    $lastuser= $this->DevStatusLog->find()->select(['action_by'])->where(['dev_id =' => $dev_id,"dev_status_master_id"=>$prevStatusId])->first();
                    $lastuserid=$lastuser->action_by;
                    $rejectComment =$dev_status_log['next_action_by_comments'];
                    $laststep= $this->DevStatusMaster->find()->select(['form_name','controller_name'])->where(["id"=>$prevStatusId])->first();
                    $controller=$laststep->controller_name;
                    $action=$laststep->form_name;
                    
                    //added by shrirang
                    $nextStatussId =$currentStatusId;
                    $current_status_id = $this->WiseWorks->getPreviousStep($nextStatussId,$dev_id);
                    $prevsStatusId = $this->WiseWorks->getPreviousStep($current_status_id,$dev_id);
                    
                    if(isset($dev_status_log['id']) && $dev_status_log['id'] != ''){
                        $lastLogId = $dev_status_log['id'];
                    }
                    else {
                        $lastLogId=null;
                    }
                    $title = "Deviation ".$result->reference_number." QA Approval rejected";
                    $this->rejectstatus($dev_id,$current_status_id,$nextStatussId,$rejectComment,$lastLogId);
                   
                }
                $this->Flash->success(__('The deviation has been saved.'));
                
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The deviation could not be saved. Please, try again.'));
        }
        $this->loadComponent('Common');
        $currentLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$currentStatusId,'DevStatusLog.action_taken !='=>'Reject',])->last();
        $preLog=$this->Deviation->DevStatusLog->find('all')->where(['DevStatusLog.dev_id'=>$id,"DevStatusLog.dev_status_master_id"=>$prevStatusId])->last();
        $userCondition=array("Users.customer_id"=>$customer_id,"Users.active"=>1);//debug($userCondition);die;
        $users=$this->Common->getUsersArray($userCondition);
        $userCondition=array("plugin"=>$plugin,"controller"=>$controller,"element_name"=>'department_head',"is_auth"=>1,'RolewisePermissions.can_approve'=>1);
        $deptHeadUsers=$this->Common->getUserDeptHead($userCondition,$loggedUserDeptId);
        $this->set(compact('currentLog','preLog', 'currentStatusId', 'nextStatusId', 'prevStatusId','statusData','deptHeadUsers','status_step'));
        $this->getDeviationData($id,true);
        $this->viewBuilder()->setTemplate("deptheadreview");
        $this->set('DateTimeFormat',$this->DateTimeFormat); //for passing variable to template
    }
    
}
