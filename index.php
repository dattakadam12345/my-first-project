<!-- Content Header (Page header) -->
<style>
.breadcrumb{
    margin-bottom:0px !important;
}
td.center, th.center{
    text-align:center;
}
th{
    color: #3c8dbc;asdasdasdasdasdada
}
.box-footer {
    background-color: transparent;
    padding:0px;
}
</style>
<?php 
use App\Utility\CustomerCache;

if($devName=="dev"){
    $isPlannedOrNot="";
}else{
    $isPlannedOrNot=($isPlanned!='un')?"Planned ":"Unplanned";
}
$dtformat=CustomerCache::read("dateinputformat");
$extendlimit_days=CustomerCache::read("devtarget_extendlimit_days");
//added shrirang
$isTransPassword = CustomerCache::read("transactional_password_required");
if ($isTransPassword == 'Y') {
    $transPass  = 1;
}
else {
    $transPass = 0;
}

?>
<ol class="breadcrumb">
	<li><a href="<?php echo $this->Url->build(['plugin'=>false,'controller'=>'pages','action' => 'home']); ?>"><i class="fa fa-dashboard"></i> Home</a></li>
	<li class="active">Deviation Summary</li>
</ol>	
<section class="content-header">
  <h1>
    Deviation Summary	
  </h1>
</section>
<br>
<div class="container-fluid">
  <div class="row">
    <div class="col-xs-12">
      <div class="box">
        <div class="box-header checkbox">
          <div class="box-body table-responsive no-padding"><br>
        <?php
            echo $this->Form->create(null,array("type"=>"GET",'id'=>'SearchForm')); 
        ?>
            <div class="col-md-12">
    	        <div class="col-md-2">
    	        <?php 
    	        echo $this->Form->control('reference_number', ['id'=>'reference-number-search','default'=>$referenceNumberData,"label"=>false,"placeholder"=>"Search By Reference Number","class"=>"form-control searchForm",]);
    	         ?>
                </div>
    	        <div class="col-md-2">
    	        <?php 
    	        echo $this->Form->control('customer_locations_id', ['id'=>'customer-location-search','default'=>$customerLocationData,"type"=>"select","label"=>false,"empty"=>"Location","class"=>"form-control select2 searchForm",'options' => $customerLocationsList]);
    	         ?>        
    	        </div>
    	        <div class="col-md-2">
    	        <?php 
    	        echo $this->Form->control('status_master_id', ['id'=>'status-search','default'=>$statusData,"type"=>"select","label"=>false,"empty"=>"Status","class"=>"form-control select2 searchForm",'options' => $statusList]);
    	         ?>        
    	        </div>
    	        <div class="col-md-2">
    	        <?php 
    	        echo $this->Form->dateinput('created', ['placeholder'=>'Date','value'=>$createdData,"label"=>false,"class"=>"erpdatepicker form-control searchForm"]);
    	         ?>        
    	        </div>
    	        <?php echo $this->Form->hidden("isSearch"); ?>
    	        <div class="col-md-1">
    	        <?php echo $this->Form->submit('Filter',['style'=>'margin-top:0px; banckground-color:red;']); ?>
    	        </div>
    	        <div class="col-md-1">
    	        <?php echo $this->Form->submit('Reset Filter',['id'=>'FormReset','style'=>'margin-right:10px;']); ?>
    	        </div>
    	        <div class="col-md-1">
	             <?php echo $this->Form->submit('Export',['id'=>'exportID', "data-tranPass"=>2,'name' =>"export",'value' =>"download",'class'=>"btn btn-success",'style'=>'margin-left:30px;']); ?>
                </div>
	        </div>
	        <?php echo $this->Form->end(); ?>
       </div>
</div>
</div>
</div>
</div>
</div>
<style>
.stepbar .step{
        margin: 0px -2px;
        display: inline-block;
        text-align: center; 
        min-width:9%;
}

.stepbar .step:first-child .prev-line{
    visibility: hidden;
}

.stepbar .step:last-child .next-line{
    visibility: hidden;
}

.stepbar .step .prev-line{
    float: left;
    
}

.stepbar .step .next-line{
    float: right;
}
.stepbar .step .line-content,.stepbar .step .next-line.line,.stepbar .step .prev-line{
    vertical-align: middle;
    display: inline-block;
}

.stepbar .step .next-line.line,.stepbar .step .prev-line.line{
    background-color: #ddd;
    padding: 1px;
    display: inline-block;
    width: calc(50% - 10px);
    margin-top: 10px; 
}
</style>
<!-- Main content -->
<section class="content">
<div class="row">
		<div class="col-xs-12">
			<div class="box">
  <ul class="nav nav-tabs">
        <li class="active">
            <a href="#tab2">Deviation Summary</a>
        </li>
        
        <li>
            <?php echo $this->Html->link(__('Tasks'), ['action' => 'devtasks'], ['title'=>"Tasks"]) ?>
        </li>
        <li>
        	<?php echo $this->Html->link(__('Completed Deviations'), ['action' => 'completedtask'], ['title'=>"Completed Deviations"]) ?>
        </li>
		<li>
            <?php echo $this->Html->link(__('Reports'), ['action' => 'reports'], ['title'=>"Deviation Reports"]) ?>
        </li>
        <li>
            <?php echo $this->Html->link(__('In Process Report'), ['action' => 'pendingreport'], ['title'=>"Deviation In Process Reports"]) ?>
        </li>
        <li>
            <?php echo $this->Html->link(__('In Process Task Report'), ['action' => 'devtaskreport'], ['title'=>"Deviation In Process Reports"]) ?>
        </li>
  </ul>
  

        <div class="box-header">
          <h3 class="box-title"><?php echo __('List of Deviations'); ?></h3>
          	<div class="pull-right">
                	<?php echo $this->Html->link(__('New'), ['action' => 'add'], ['class'=>'btn btn-success btn', 'title'=>"New Deviation"]) ?>
                	<?php echo $this->Html->link(__('Customer Deviation'), ['action' => 'addcustomerdeviation'], ['class'=>'btn btn-success btn', 'title'=>"New Customer Deviation"]) ?>
            </div>
          <div class="box-tools">
          </div>
        </div>
        <!-- /.box-header -->
        <div class="box-body table-responsive no-padding">
          <table class="table table-hover">
            <thead>
              <tr>
              	  <th width="10%" scope="col"><?= $this->Paginator->sort('reference_number','Ref. No') ?></th>
              	  <th width="18%" scope="col"><?= $this->Paginator->sort('customer_locations_id','Initiated by') ?></th>
              	  <th width="32%" scope="col"><?= $this->Paginator->sort('title') ?></th>
              	  <th width="15%" scope="col">Status</th>
              	  <th width="15%" scope="col">Extend Target Date</th>
              	  <th width="10%" scope="col" class="actions text-center">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($deviation as $deviation): 
              
              $applicableSteps=$deviation->applicable_stpes;
              
              if(isset($deviation->created_by_customer)){
                  if($deviation->created_by_customer == 1){
                      $bgcolor='#faa5a5';
                  }
                  else {
                      $bgcolor='';
                  }
              }
              $critical="No";
              if(!empty($deviation['dev_investigation'])){
                  if(isset($deviation['dev_investigation'][0]['final_classification'])){
                      $critical=$deviation['dev_investigation'][0]['final_classification'];
                  }
              }
                    $lastRecord=count($deviation['dev_status_log']);
                    $lastRecord=$lastRecord-1;
                    $progress=isset($deviation['dev_status_log'][$lastRecord])?($deviation['dev_status_log'][$lastRecord]['is_complete']==1)?"":"(In Progerss)":"";
              ?>
                <tr class="dev-data" style="background-color:<?= $bgcolor ?>">
                  <td width="10%"><?= isset($deviation->reference_number)?$deviation->reference_number:'' ?></td>
                  <td width="10%"><?= !empty($deviation->CreatedByUser)?$deviation->CreatedByUser->userfullname:'' ?></td>
                  <td width="25%"><?= isset($deviation->title)?$deviation->title:'' ?></td>
                  <td width="15%">
                  	<?php
                  	  	  $current_status="";
                  	  	  foreach($applicableSteps as $status){
                  	  	      if($status['status_master_id']==$deviation['dev_status_master_id']){
                  	  	          $current_status=$status['display_name'];
                  	  	          break;
                  	  	      }
                  	  	  }
                  	  	  
                  	  	  $totalStatus=count($devStatusMaster);
                  	  	  $statusMaster=$devStatusMaster;               	  	  
                  	  	  
                  	  	  foreach ($statusMaster as $k=>$val)
                  	  	  {
                  	  	      $status_id = $val->id;
                  	  	      foreach ($applicableSteps as $j=>$value)
                  	  	      {
                  	  	          if ( $value['status_master_id'] == $status_id) {
                  	  	              $val['status_data']=$value;
                      	  	      }
                  	  	      }
                  	  	  }
                  	  	  
                  	  	?>
                  	  	<label class="label label-success" >
                  	  		<i class="fa fa-check-square-o"></i> <?php echo h($current_status) ?>
                  	  	</label>
                  	  	<a class="label label-default show-status-button"> Status <i class="fa fa-angle-down"></i> </a>
                  </td>
                  
                    <td>
                  <?php 
                  if($deviation['action_plan_closed'] =='N'){
                  $approved = 0;
                  if(!empty($deviation['dev_target_extension'])){
                      foreach ($deviation['dev_target_extension'] as $key=>$value){
                          if($value['is_active']=='Y'){
                              $approved=$approved+1;
                          }
                      }
                  }
                  if($approved>0){
                  ?>
              		<a class="btn btn-warning btn btn-xs" data-toggle="modal" data-target="<?php echo "#aetd".$deviation->id;?>" >Approve Extend Date</a>
              	  <?php }else{?>
                  	<a class="btn btn-success btn btn-xs" data-toggle="modal" data-target="<?php echo "#etd".$deviation->id;?>" >Extend Target Date</a>
                  <?php }}?>
                  </td>
                  
              	  <td class="actions center" width="15%">
                      <?php
                      echo $this->Html->link(__('view'), ['action' => 'view', encryptVal($deviation->id),encryptVal(false)], ['class'=>'btn btn-info btn-xs']);?>&nbsp;&nbsp;&nbsp;&nbsp;
                      <?php echo $this->Html->link(__('Pdf'), ['plugin'=>false,'controller'=>'Pdf','action' => 'generatepdf', encryptVal($deviation->id),encryptVal("getDeviationData")], ['class'=>'btn btn-warning btn-xs','target'=>'_blank']);?>
                  </td>
                </tr>
                <tr class="dev-status" style="display: none">
                	<td colspan="6">
                		<div style="text-align: center;font-size: 13px !important;">
                    		<div class="stepbar" style="text-align:left;display: inline-block;width:100%">
                    			<?php $allStepCompleted=0;
                    			     $step_active = 0;
                    			     $totalSteps=count($applicableSteps);
                    			     foreach($statusMaster as $index=>$rows){
                    			         if (isset($rows['status_data'])) {
                    			             $status_data=$rows['status_data'];
                    			             if($deviation->action_taken_immediate==false && $rows['form_name']=='reviewdh'){
                    			                 continue;
                    			             }
                    			        $isDone=0;
                    			        foreach($deviation['dev_status_log'] as $dev_status_log){//debug($rows['id'].'-'.$dev_status_log['dev_status_master_id']);
                    			            if($rows["id"]==$dev_status_log['dev_status_master_id'] && $dev_status_log['step_complete']==1){
                    			                $isDone=1;
                    			                break;
                    			            }else if($rows["id"]==$dev_status_log['dev_status_master_id'] && $dev_status_log['step_complete']==0){
                    			                if($rows['id'] < $totalStatus){
                    			                    $allStepCompleted++;
                    			                }
                    			                $isDone=0;
                    			                break;
                    			            }else{
                    			                $isDone=-1;
                    			                
                    			            }
                    			        }
                    			        if($isDone == -1){
                    			            if($rows['id'] < $totalStatus){
                        			            $allStepCompleted++;
                        			            
                        			        }
                    			        }
                    			        
//                     			        debug($isDone);
                    			        $nextStatusId=$status_data['nextstepId'];
                    			        $prevStatusId=$status_data['previousstepId'];
                    			        $currentStatusId=$status_data['status_master_id'];
                    			        $optionalRejetStatusId=$status_data['optional_rejet_status_id'];
                    			        $link=$rows['status'];
                    			        $iClass="";
                    			        $iTitle="";
                    			        if($isDone==1){
                    			            if($deviation['dev_status_master_id'] < $rows['editable_up_to']){
                    			                $row_form_name=$rows['form_name'];
                    			                $row_controller_name=$rows['controller_name'];
                    			               
                    			                    $link=$this->Html->link($rows['display_status'], ['controller'=>$row_controller_name,'action' =>$row_form_name, encryptVal($deviation->id),encryptVal($currentStatusId), encryptVal($nextStatusId), encryptVal($prevStatusId),encryptVal($optionalRejetStatusId)], ["title"=>"Completed"]);
                    			                
                    			            }else{
                    			                $link=$this->Html->link($rows['display_status'], ['action' =>"view", encryptVal($deviation->id)], ["title"=>"Completed"]);
                    			            }
                    			            $iClass="fa-check-circle";
                    			            $iTitle="Completed";
                    			        }else if($isDone==0){
                    			            $row_form_name=$rows['form_name'];
                			                $row_controller_name=$rows['controller_name'];
                			                
                			                if($step_active==0){
                			                $link=$this->Html->link($rows['display_status'], ['controller'=>$row_controller_name,'action' =>$row_form_name, encryptVal($deviation->id),encryptVal($currentStatusId), encryptVal($nextStatusId), encryptVal($prevStatusId),encryptVal($optionalRejetStatusId)], ['class'=>'btn btn-warning btn-xs',"title"=>"In Progress"]);
                			                $iClass="fa-exclamation-circle";
                			                $iTitle="In Progress";
                			                $step_active=1;
                			                }else{
                			                    $iClass="fa fa-circle-o";
                			                    $iTitle="Not Started";
                			                    
                			                }
                    			           
                    			        }else{
                    			            
                    			            $prev_status=$prevStatusId;
                    			            if($rows['id'] == $totalStatus){
                    			                    if($allStepCompleted == 0){
                    			                        $row_form_name=$rows['form_name'];                    			                       
                    			                        $row_controller_name=$rows['controller_name'];
                    			                        if($row_controller_name=='DevInvestigation'){
                    			                            $link=$this->Html->link($rows['display_status'], ['controller'=>$row_controller_name,'action' =>$row_form_name, encryptVal($deviation->id),encryptVal($currentStatusId), encryptVal($nextStatusId), encryptVal($prevStatusId),encryptVal($optionalRejetStatusId)], ['class'=>'btn btn-success btn-xs',"title"=>"Click to Start Step"]);
                    			                        }else{
                    			                            $link=$this->Html->link($rows['display_status'], ['controller'=>$row_controller_name,'action' =>$row_form_name, encryptVal($deviation->id),encryptVal($currentStatusId), encryptVal($nextStatusId), encryptVal($prevStatusId),encryptVal($optionalRejetStatusId)], ['class'=>'btn btn-success btn-xs',"title"=>"Click to Start Step"]);
                    			                        }
                    			                        $iClass="fa-play-circle";
                    			                        $iTitle="Start Step";
                    			                    }else{
                    			                        $link=$rows['status'];
                    			                        $iClass="fa fa-circle-o";
                    			                        $iTitle="Cannot Start";
                    			                    }
                    			                }else{
                    			                    
                    			                    if($prevStatusId==$deviation['dev_status_log'][0]['dev_status_master_id'] && $deviation['dev_status_log'][0]['step_complete']==1){
                            			                $row_form_name=$rows['form_name'];
                            			                $row_controller_name=$rows['controller_name'];
                            			                
                            			                     $link=$this->Html->link($rows['display_status'], ['controller'=>$row_controller_name,'action' =>$row_form_name, encryptVal($deviation->id),encryptVal($currentStatusId), encryptVal($nextStatusId), encryptVal($prevStatusId),encryptVal($optionalRejetStatusId)], ['class'=>'btn btn-success btn-xs',"title"=>"Click to Start Step"]);
                            			               
                            			                $iClass="fa-play-circle";
                            			                $iTitle="Start Step";
                    			                    }else{
                    			                        if($rows['substep_from_to']=="0"){
                    			                            $link=$rows['status'];
                    			                            $iClass="fa fa-circle-o";
                    			                            $iTitle="Not Started";
                    			                        }else{
                    			                            if(isset($substep_from_to) && !empty($substep_from_to))
                    			                            {
                    			                            $substep_from_to=explode("-",$rows['substep_from_to']);
                    			                            if(($deviation->dev_status_master_id >= $substep_from_to[0] &&  $deviation->dev_status_master_id < $substep_from_to[1]) && ($rows["id"] >= $substep_from_to[0] &&  $rows["id"] < $substep_from_to[1])){
                    			                                $row_form_name=$rows['form_name'];
                    			                                $row_controller_name=$rows['controller_name'];
                    			                                    $link=$this->Html->link($rows['display_status'], ['controller'=>$row_controller_name,'action' =>$row_form_name, encryptVal($deviation->id),encryptVal($currentStatusId), encryptVal($nextStatusId), encryptVal($prevStatusId),encryptVal($optionalRejetStatusId)], ['class'=>'btn btn-success btn-xs',"title"=>"Click to Start Step"]);
                    			                                
                    			                                $iClass="fa-play-circle";
                    			                                $iTitle="Start Step";
                    			                            }else{
                    			                                $link=$rows['status'];
                    			                                $iClass="fa fa-circle-o";
                    			                                $iTitle="Not Started";
                    			                            }
                    			                            }
                    			                            else{
                    			                                $link=$rows['status'];
                    			                                $iClass="fa fa-circle-o";
                    			                                $iTitle="Not Started";
                    			                            }
                    			                        }
                    			                    }
                    			                }
                    			            
                    			        }
                    			        $color=($isDone==1)?"success":"default";
                    			    ?>
                    				
                        				<span class="step" style=" ">   
                              	     	<span class="prev-line line"></span>
                              	     	<i class="line-content text-<?php echo $color ?> fa <?php echo $iClass ?>" title="<?php echo $iTitle;?>"></i> 
                              	     	<span class="next-line line"></span>
                              	     	<br/>
                              	     	<span class="status-label">
                              	     		<?php echo $link;?>
                                  	    </span>	
                                  	    </span>	
                                  	  <?php }?>
                    			<?php } ?>
                    		</div>
                		</div>
                	</td>
                </tr>
                
                  <!-- Target Date Extention Start -->
                <div class="modal fade tagetdatehide" id="<?php echo "etd".$deviation->id;?>" tabindex="-1" role="dialog" aria-labelledby="myModalLabel">
                  <div class="modal-dialog" role="document">
                    <div class="modal-content">
                      <?php echo $this->Form->create(null,['role'=>'form','url' => ['action' => 'extendTargetDate',$deviation->id]]); ?>
                      <div class="modal-header bg-primary">	<h4> <i class="glyphicon glyphicon-hand-right "></i> &nbsp; <?php echo __("Do you want to extend target date of Deviation No. "). __( $deviation['reference_number']." ?"); ?> </h4></div>	
                      <div class="modal-body">
                      	<div class="col-md-6" style="padding: 0;">
                          	<label>Current Target Date : </label>
                          	<?php echo $this->Form->dateinput('current_target_date',["label"=>false,"class"=>"erpdatepicker form-control","id"=>"currenttargetdate",'value'=>$deviation['target_date'],'disabled'=>true])."<br>";?> 
                      	</div>
                      	<div class="col-md-6">
                          	<label>New Target Date</label><br>
                          	<?php echo $this->Form->dateinput('new_target_date',["label"=>false,'required'=>'required',"id"=>"targetdate"])."<br>";?>
                      	</div>
                      	<?php 
                      	echo $this->Form->hidden("deviation_id",['value'=>$deviation->id]);
                      	 echo $this->Form->hidden('previous_target_date',["label"=>false,"class"=>"erpdatepicker form-control targetdate",'required'=>'required','value'=>isset($deviation->target_date)?$this->Qms->getFormatedDate($deviation->target_date):'-']);
                      	 echo $this->Form->hidden("created_by",["value"=>$loggedUserId]);
                  	     echo $this->Form->input("justification",[
                  	         "placeholder"=>__("Please enter justification target date extention."),
                  	         "type"=>"textarea",
                  	         'required'=>'required'
                  	     ]); 
                      	?>
                      	  
                      </div>
                      <div class="modal-footer">
                      <div class="col-md-2">
                        <button type="button"  class="btn btn-primary" data-dismiss="modal"> <i class="fa fa-times" ></i> &nbsp; <?php echo __("Cancel") ?></button>
                       </div>
                         <div class="col-md-2">
                       <?php echo $this->Form->submit(__("Save & Send To Approve"),["id"=>"saveapp", "data-tranPass"=>$transPass]); ?>
                       </div>
                      </div>
                      <?php echo $this->Form->end(); ?>
                    </div>
                  </div>
                </div>
                <!-- Target Date Extention End -->
                <!-- Target Date Extention Start -->
                    <div class="modal fade targethide" id="<?php echo "aetd".$deviation->id;?>" tabindex="-1" role="dialog" aria-labelledby="myModalLabel">
                      <div class="modal-dialog" role="document">
                        <div class="modal-content">
                          <?php echo $this->Form->create(null,['role'=>'form','url' => ['action' => 'approveRejectExtendTargetDate',$deviation->id]]); ?>
                          <div class="modal-header bg-primary">	<h4> <i class="glyphicon glyphicon-hand-right "></i> &nbsp; <?php echo __("Do you want to extend target date of Deviation No. "). __( $deviation['reference_number']." ?"); ?> </h4></div>	
                          <div class="modal-body">
                          <?php 
                          foreach ($deviation['dev_target_extension'] as $key=>$value){
                              if($value['approved_by']==null && $value['is_active']=='Y'){?>
                         	<div class="col-md-6" style="padding: 0;">
                              	<label>Current Target Date : </label>
                              	<?php echo $this->Form->dateinput('previous_target_date',["label"=>false,"class"=>"erpdatepicker form-control targetdate",'value'=>$value['previous_target_date'],'disabled'=>true])."<br>";?> 
                          	</div>
                          	<div class="col-md-6">
                              	<label>New Target Date</label><br>
                              	<?php echo $this->Form->dateinput('new_target_date',["label"=>false,"class"=>"erpdatepicker form-control targetdate",'required'=>'required','value'=>$value['new_target_date'],'disabled'=>true])."<br>";?>
                          	</div>   
                         
                          	<?php 
                          	 echo $this->Form->hidden("id",['value'=>$value->id]);
                          	 echo $this->Form->hidden("deviation_id",['value'=>$value->deviation_id]);
                          	 echo $this->Form->hidden("action_by",["value"=>$loggedUserId]);
                      	     echo $this->Form->input("action_taken_remark",[
                      	         "placeholder"=>__("Please enter remark target date extention."),
                      	         "type"=>"textarea",
                      	         'required'=>'required'
                      	     ]); 
                          	?>
                          	  <?php }
                          }
                          ?>
                          </div>
                          <div class="modal-footer">
                          <div class="col-md-6"></div>
                          	<div class="col-md-2">
                            	<button type="button"  class="btn btn-primary" data-dismiss="modal"> <i class="fa fa-times" ></i> &nbsp; <?php echo __("Cancel") ?></button>
                            </div>
                            <div class="col-md-2">
                            <?php
                        	   echo $this->Form->submit(__("Approve"), [
                            	    "name" => "Approve",
                            	    "id" => "Approve",
                        	       "data-tranPass"=>$transPass
                            	]);
                        	?>
                        	</div>
                        	<div class="col-md-2">
                        	<?php 
                        	   echo $this->Form->submit(__("Reject"), [
                            	    "name" => "Reject",
                            	    "id" => "Reject",
                        	       'class'=>'btn btn-danger',
                        	       "data-tranPass"=>$transPass
                            	]);
                        	 ?>
                        	 </div>
                          </div>
                          <?php echo $this->Form->end(); ?>
                        </div>
                      </div>
                    </div>
                    <!-- Target Date Extention End -->
                
                
              <?php endforeach; ?>
            </tbody>
          </table> 
        </div>
        <div class="content-header clearfix">
            <?php 
            echo $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total')); ?>
            <ul class="pagination pagination-sm no-margin pull-right">
              
                <?php echo $this->Paginator->numbers(); ?>
              </ul>
        </div><br>
        <!-- /.box-body -->
      </div>
      <!-- /.box -->
    </div>
  </div>
</section>
<?php $this->start("scriptBottom"); ?>
<script type="text/javascript">
$(document).ready(function(){
	var craetedDate="<?= $createdData ?>";
	if(craetedDate == ""){
		$('input[name=created]').val('');
	}
	$(".select2").select2();
});
	$("tr.dev-status").hide();
	$(document).on("click",".show-status-button", function(){
		var style=$(this).parents('tr').next().attr("style");
		if($(this).parents('tr').next().is(":visible")){
			$(this).parents('tr').next().hide();
		}else{
			$("tr.dev-status").hide();
			$(this).parents('tr').next().show();
		}
	});


	$(function() {
	    $("#targetdate").change(function() {
	    	var datelimit = <?php echo $extendlimit_days;?>;
			var newdate = $(this).val();
			var prevDate = $('#currenttargetdate').val();
			$.post("<?php echo $this->Url->build(array('plugin'=>null,'controller' => 'Polyajax', 'action' => 'getDateDiff')); ?>",
					 { lastDate:prevDate,newDate:newdate})
			.done(function( data ) {
				//console.log(data);
				if(data > datelimit)
				{
					$("#targetdate").val('');
					alert("Target Extention date is gretter than limit ");
				}
			});
			
	    });
	});
	//added shrirang
$(document).on("click","#saveapp", function(){
   $('.tagetdatehide').modal('hide');
  });
$(document).on("click",'#Approve,#Reject', function(){
    $('.targethide').modal('hide');
  });
  	$(document).on("click","#FormReset",function(e){
		$('.searchForm').val('');
		$('input[name=created]').val('');
		//e.preventDefault();
	});
</script>
<?php $this->end(); ?>