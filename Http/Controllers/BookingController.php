<?php

namespace DTApi\Http\Controllers;

use DTApi\Helpers\BookingJob;
use DTApi\Helpers\Notificaton;
use Illuminate\Http\Request;
use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller{
    /**
     * @var BookingRepository
     */
    protected $repository;
    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository){
        $this->repository = $bookingRepository;
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request) {
        if($request->has('user_id')) {
            $userID  = $request->get('user_id');
            $response = $this->repository->getUsersJobs($userID);
        }
        elseif($request->__authenticatedUser->user_type == env('ADMIN_ROLE_ID') || $request->__authenticatedUser->user_type == env('SUPERADMIN_ROLE_ID')){
            $response = $this->repository->getAll( $request );
        }
        return response( $response );
    }
    /**
     * @param $id
     * @return mixed
     */
    public function show($id){
        $job = $this->repository->with('translatorJobRel.user')->find( $id );
        return response( $job );
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request){
        $data       = $request->all();
        $response   = Notificaton::store( $request->__authenticatedUser, $data );
        return response( $response );
    }
    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request){
        $data       = $request->all();
        $cuser      = $request->__authenticatedUser;
        $response   = BookingJob::updateJob( $id, array_except($data, ['_token', 'submit']), $cuser );
        return response( $response );
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request){
        $data               = $request->all();
        $response           = Notificaton::storeJobEmail( $data );
        return response( $response );
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request){
        if( $request->has('user_id') ) {
            $userID     = $request->get('user_id');
            $response   = BookingJob::getUsersJobsHistory($userID, $request);
            return response( $response );
        }
        return null;
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request){
        $data       = $request->all();
        $user       = $request->__authenticatedUser;
        $response   = BookingJob::acceptJob($data, $user);
        return response( $response );
    }
    public function acceptJobWithId(Request $request){
        $data = $request->get('job_id');
        $user = $request->__authenticatedUser;
        $response = BookingJob::acceptJobWithId($data, $user);
        return response( $response );
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request) {
        $data = $request->all();
        $user = $request->__authenticatedUser;
        $response = BookingJob::cancelJobAjax($data, $user);
        return response( $response );
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request){
        $data = $request->all();
        $response = BookingJob::endJob( $data );
        return response( $response );
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function customerNotCall(Request $request){
        $data = $request->all();
        $response = Notificaton::customerNotCall( $data ) ;
        return response( $response );
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request){
        $data = $request->all();
        $user = $request->__authenticatedUser;
        $response = BookingJob::getPotentialJobs( $user );
        return response( $response );
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function distanceFeed(Request $request){
        // Variables Declare
        $distance   =  $time = $session =  $jobID = $adminComment = "";
        $flagged    =  $manuallyHandled =  $byAdmin = 'no';
        $data       =  $request->all();
        if (isset($data['distance']) && $data['distance'] != "") {
            $distance = $data['distance'];
        }
        if (isset($data['time']) && $data['time'] != "") {
            $time = $data['time'];
        }
        if (isset($data['jobid']) && $data['jobid'] != "") {
            $jobID = $data['jobid'];
        }
        if (isset($data['session_time']) && $data['session_time'] != "") {
            $session = $data['session_time'];
        } 
    
        if ($data['flagged'] == 'true') {
            $flagged = 'yes';
            if($data['admincomment'] == '') return "Please, add comment";  
        } 
        if ($data['manually_handled'] == 'true') {
            $manuallyHandled = 'yes';
        } 
        if ($data['by_admin'] == 'true') {
            $byAdmin = 'yes';
        } 
        if (isset($data['admincomment']) && $data['admincomment'] != "") {
            $adminComment = $data['admincomment'];
        } 

        if ($time || $distance) {
            $updateData = array('distance' => $distance, 'time' => $time);
            Distance::where('job_id', '=', $jobID)->update( $updateData );
        }
        if ($adminComment || $session || $flagged || $manuallyHandled || $byAdmin) {
            $updateData  = array('admin_comments' => $adminComment, 'flagged' => $flagged, 'session_time' => $session, 'manually_handled' => $manuallyHandled, 'by_admin' => $byAdmin);
            Job::where('id', '=', $jobID)->update( $updateData );
        }
        return response('Record updated!');
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function reOpen(Request $request){
        $data       = $request->all();
        $response   = $this->repository->reOpen( $data );
        return response( $response );
    }
    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendNotifications(Request $request){
        $data       = $request->all();
        $job        = $this->repository->find( $data['jobid'] );
        $jobData    = $this->repository->jobToData( $job );
        Notification::sendNotificationTranslator( $job, $jobData, '*' );
        return response( ['success' => 'Push sent'] );
    }
    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request){
        $data   = $request->all();
        $job    = $this->repository->find( $data['jobid'] );
        BookingJob::jobToData( $job );
        try {
            Notification::sendSMSNotificationToTranslator( $job );
            return response( ['success' => 'SMS sent'] );
        } 
        catch (\Exception $e) {
            return response( ['success' => $e->getMessage()] );
        }
    }
}