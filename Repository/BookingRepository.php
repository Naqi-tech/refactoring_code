<?php
namespace DTApi\Repository;

use Carbon\Carbon;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Events\SessionEnded;
use DTApi\Models\Translator;
use DTApi\Models\Job;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Models\User;
use DTApi\Models\UsersBlacklist;
use DTApi\Models\UserLanguages;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Helpers\Notificaton;
use DTApi\Helpers\SendSMSHelper;
use DTApi\Helpers\TeHelper;
use DTApi\Mailers\AppMailer;
use DTApi\Mailers\MailerInterface;
use Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer){
        parent::__construct( $model );
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');
        $this->logger->pushHandler(new StreamHandler(storage_path( env("ADMIN_FILE_LOG_NAME") . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param integer $userID
     * @return array
     */
    public function getUsersJobs($userID){
        $cuser          = User::find( $userID );
        $userType       = '';
        $emergencyJobs  = [];
        $noramlJobs     = [];

        if ($cuser && $cuser->is('customer')) {
            $userType = 'customer';
            $jobs = $cuser->jobs()
                                    ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                                    ->whereIn('status', ['pending', 'assigned', 'started'])
                                    ->orderBy('due', 'asc')
                                    ->get();
        } elseif ($cuser && $cuser->is('translator')) {
            $userType   = 'translator';
            $jobs       = Job::getTranslatorJobs($cuser->id, 'new');
            $jobs       = $jobs->pluck('jobs')->all();
        }
        if ( $jobs ) {
            foreach ($jobs as $jobItem) {
                if ($jobItem->immediate == 'yes') {
                    $emergencyJobs[] = $jobItem;
                } 
                else {
                    $noramlJobs[] = $jobItem;
                }
            }
            $noramlJobs = collect( $noramlJobs )->each(function ( $item, $key ) use ( $userID ) {
                $item['usercheck'] = Job::checkParticularJob( $userID, $item );
            })->sortBy('due')->all();
        }
        return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'cuser' => $cuser, 'usertype' => $userType];
    }
    /**
     * @param integer $userID
     * @param Request $request
     * @return array
     */
    public function getUsersJobsHistory($userID, Request $request){
        $pagenum        = "1";
        $userType       = '';
        $emergencyJobs  = [];
        $noramlJobs     = [];
        $cuser          = User::find( $userID );
        if ($request->has('page')) {
            $pageNum = $request->get('page');;
        } 
        if ($cuser && $cuser->is('customer')) {
            $userType   = 'customer';
            $jobs       =  $cuser->jobs()
                                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                                ->orderBy('due', 'desc')
                                ->paginate(15);
            return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => [], 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $userType, 'numpages' => 0, 'pagenum' => 0];
        } 
        elseif ($cuser && $cuser->is('translator')) {
            $userType   = 'translator';
            $jobsIDs    = Job::getTranslatorJobsHistoric( $cuser->id, 'historic', $pageNum );
            $totalJobs  = $jobsIDs->total();
            $numpages   = ceil( $totalJobs / 15 );
            $jobs       = $jobsIDs;
            $noramlJobs = $jobsIDs;
            return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $userType, 'numpages' => $numpages, 'pagenum' => $pageNum];
        }
    }
    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data){
        $immediatetime = 5;
        $consumerType = $user->userMeta->consumer_type;
        if ($user->user_type == env('CUSTOMER_ROLE_ID')) {
            $cuser = $user;
            if (!isset($data['from_language_id'])) {
                $response['status']     = 'fail';
                $response['message']    = "Du måste fylla in alla fält";
                $response['field_name'] = "from_language_id";
                return $response;
            }
            if ($data['immediate'] == 'no') {
                if (isset($data['due_date']) && $data['due_date'] == '') {
                    $response['status']     = 'fail';
                    $response['message']    = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_date";
                    return $response;
                }
                if (isset($data['due_time']) && $data['due_time'] == '') {
                    $response['status']     = 'fail';
                    $response['message']    = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_time";
                    return $response;
                }
                if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                    $response['status']     = 'fail';
                    $response['message']    = "Du måste göra ett val här";
                    $response['field_name'] = "customer_phone_type";
                    return $response;
                }
                if (isset($data['duration']) && $data['duration'] == '') {
                    $response['status']     = 'fail';
                    $response['message']    = "Du måste fylla in alla fält";
                    $response['field_name'] = "duration";
                    return $response;
                }
                return array( ["status"=>"fail", "message"=>"Field Name not Found", "field_name"=>""] );
            } 
            else {
                if (isset($data['duration']) && $data['duration'] == '') {
                    $response['status']     = 'fail';
                    $response['message']    = "Du måste fylla in alla fält";
                    $response['field_name'] = "duration";
                    return $response;
                }
            }
            if (isset($data['customer_phone_type'])) {
                $data['customer_phone_type'] = 'yes';
            }
            else{
                $data['customer_phone_type'] = 'no';
            }

            if (isset($data['customer_physical_type'])) {
                $data['customer_physical_type']     = 'yes';
                $response['customer_physical_type'] = 'yes';
            } 
            else {
                $data['customer_physical_type']     = 'no';
                $response['customer_physical_type'] = 'no';
            }

            if ($data['immediate'] == 'yes') {
                $dueCarbon          = Carbon::now()->addMinute($immediatetime);
                $data['due']        = $dueCarbon->format('Y-m-d H:i:s');
                $data['immediate']  = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type']   = 'immediate';
            } 
            else {
                $due = $data['due_date'] . " " . $data['due_time'];
                $response['type'] = 'regular';
                $dueCarbon = Carbon::createFromFormat('m/d/Y H:i', $due);
                $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
                if ( $dueCarbon->isPast() ) {
                    $response['status'] = 'fail';
                    $response['message'] = "Can't create booking in past";
                    return $response;
                }
            }
            if (in_array('male', $data['job_for'])) {
                $data['gender'] = 'male';
            } 
            else if (in_array('female', $data['job_for'])) {
                $data['gender'] = 'female';
            }
            if (in_array('normal', $data['job_for'])) {
                $data['certified'] = 'normal';
            }
            else if (in_array('certified', $data['job_for'])) {
                $data['certified'] = 'yes';
            } 
            else if (in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'law';
            } 
            else if (in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'health';
            }
            if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
                $data['certified'] = 'both';
            }
            else if(in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for'])){
                $data['certified'] = 'n_law';
            }
            else if(in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for'])){
                $data['certified'] = 'n_health';
            }
            if ($consumerType == 'rwsconsumer'){
                $data['job_type'] = 'rws';
            }
            else if ($consumerType == 'ngo'){
                $data['job_type'] = 'unpaid';
            }
            else if ($consumerType == 'paid'){
                $data['job_type'] = 'paid';
            }
            $data['b_created_at'] = date('Y-m-d H:i:s');
            if (isset($due)){
                $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
            }
            $data['by_admin']  = isset($data['by_admin']) ? $data['by_admin'] : 'no';
            $data['job_for']    = array();
            $job = $cuser->jobs()->create($data);
            $response['status'] = 'success';
            $response['id']     = $job->id;
           
            if ($job->gender != null) {
                if ($job->gender == 'male') {
                    $data['job_for'][] = 'Man';
                } 
                else if ($job->gender == 'female') {
                    $data['job_for'][] = 'Kvinna';
                }
            }
            if ($job->certified != null) {
                if ($job->certified == 'both') {
                    $data['job_for'][] = 'normal';
                    $data['job_for'][] = 'certified';
                } 
                else if ($job->certified == 'yes') {
                    $data['job_for'][] = 'certified';
                } 
                else {
                    $data['job_for'][] = $job->certified;
                }
            }
            $data['customer_town'] = $cuser->userMeta->city;
            $data['customer_type'] = $cuser->userMeta->customer_type;
        } 
        else {
            $response['status']  = 'fail';
            $response['message'] = "Translator can not create booking";
        }
        return $response;
    }
    
    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job){
        $translatorLevel = [];
        /* Job Type Check */
        $jobType  = $job->job_type;
        if ($jobType == 'paid')
            $translatorType = 'professional';
        else if ($jobType == 'rws')
            $translatorType = 'rwstranslator';
        else if ($jobType == 'unpaid')
            $translatorType = 'volunteer';

        $joblanguage = $job->from_language_id;
        $gender      = $job->gender;
       
        if (!empty($job->certified)) {
            if ($job->certified == 'yes' || $job->certified == 'both') {
                $translatorLevel[] = 'Certified';
                $translatorLevel[] = 'Certified with specialisation in law';
                $translatorLevel[] = 'Certified with specialisation in health care';
            }
            elseif($job->certified == 'law' || $job->certified == 'n_law') {
                $translatorLevel[] = 'Certified with specialisation in law';
            }
            elseif($job->certified == 'health' || $job->certified == 'n_health'){
                $translatorLevel[] = 'Certified with specialisation in health care';
            }
            else if ($job->certified == 'normal' || $job->certified == 'both') {
                $translatorLevel[] = 'Layman';
                $translatorLevel[] = 'Read Translation courses';
            }
            elseif ($job->certified == null) {
                $translatorLevel[] = 'Certified';
                $translatorLevel[] = 'Certified with specialisation in law';
                $translatorLevel[] = 'Certified with specialisation in health care';
                $translatorLevel[] = 'Layman';
                $translatorLevel[] = 'Read Translation courses';
            }
        }
        $blacklist      = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId  = collect($blacklist)->pluck('translator_id')->all();
        $users          = User::getPotentialUsers( $translatorType, $joblanguage, $gender, $translatorLevel, $translatorsId );
        return $users;
    }
    
    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data){
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }
    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data) {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();
                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                } 
                else{
                    $email = $user->email;
                }
                $name = $user->name;
                $dataEmail = ['user' => $user,'job'  => $job];
                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
                $user   = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
                $email  = $user->user->email;
                $name   = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }
    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job){
        $translatorChanged = false;
        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $newTranslator = $current_translator->toArray();
                $newTranslator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $logData[] = ['old_translator' => $current_translator->user->email,'new_translator' => $newTranslator->user->email];
                $translatorChanged = true;
            } 
            elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if($data['translator_email'] != '') {
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                }
                $newTranslator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $logData[] = ['old_translator' => null,'new_translator' => $newTranslator->user->email];
                $translatorChanged = true;
            }
            if ($translatorChanged)
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $newTranslator, 'log_data' => $logData];
        }
        return ['translatorChanged' => $translatorChanged];
    }
    /**
     * @param $oldDue
     * @param $newDue
     * @return array
     */
    private function changeDue($oldDue, $newDue){
        $dateChanged = false;
        if ($oldDue != $newDue) {
            $logData = ['old_due' => $oldDue, 'new_due' => $newDue ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $logData];
        }
        return ['dateChanged' => $dateChanged];
    }
    public function bookingExpireNoAccepted(){
        $languages          = Language::where('active', '1')->orderBy('language')->get();
        $requestdata        = Request::all();
        $allCustomers       = DB::table('users')->where('user_type', '1')->lists('email');
        $allTranslators     = DB::table('users')->where('user_type', '2')->lists('email');
        $cuser              = Auth::user();
        TeHelper::getUsermeta($cuser->id, 'consumer_type');
        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }
        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $allCustomers, 'all_translators' => $allTranslators, 'requestdata' => $requestdata];
    }
    public function ignoreExpiring($id){
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }
    public function ignoreExpired($id){
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }
    public function ignoreThrottle($id){
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }
    public function reOpen( $request ){
        $data   = [];
        $jobid  = $request['jobid'];
        $userid = $request['userid'];
        $job    = Job::find($jobid);
        $job    = $job->toArray();
        
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['user_id'] = $userid;
        $data['job_id'] = $jobid;
        $data['cancel_at'] = Carbon::now();

        $datareopen = array();
        $datareopen['status'] = 'pending';
        $datareopen['created_at'] = Carbon::now();
        $datareopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $datareopen['created_at']);

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', '=', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } 
        else {
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['updated_at'] = date('Y-m-d H:i:s');
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;
            $affectedRows = Job::create($job);
            $newJobid = $affectedRows['id'];
        }
        Translator::where('job_id', $jobid)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
        $Translator = Translator::create($data);
        if (isset($affectedRows)) {
            Notificaton::sendNotificationByAdminCancelJob( $newJobid );
            return ["Tolk cancelled!"];
        } 
        else {
            return ["Please try again!"];
        }
    }
    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins( $time,string $format = '%02dh %02dmin'){
        if ($time < 60) {
            return $time . 'min';
        } 
        else if ($time == 60) {
            return '1h';
        }
        $hours = floor($time / 60);
        $minutes = ($time % 60);
        return sprintf($format, $hours, $minutes);
    }
}