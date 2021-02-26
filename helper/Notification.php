<?php
namespace DTApi\Helpers;
use DTApi\Models\Job;
/**
 * Class Notification
 * @package DTApi\Helpers
 */
class Notification{
    /**
     * @param $job
     * @param array $data
     * @param integer $excludeUserID
     */
    public function sendNotificationTranslator($job,array $data = [], $excludeUserID){
        $translatorData         = []; // suitable translators (no need to delay push)
        $delpayTranslatorData   = []; // suitable translators (need to delay push)

        $users      = User::all();
        foreach ($users as $oneUser) {
            if ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $excludeUserID) { // user is translator and he is not disabled
                if (!$this->isNeedToSendPush($oneUser->id)) continue;
                $notGetEmergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $notGetEmergency == 'yes') continue;
                $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // get all potential jobs of this user
                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) { // one potential job is the same with current job
                        $userId = $oneUser->id;
                        $jobForTranslator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                        if ($jobForTranslator == 'SpecificJob') {
                            $jobChecker = Job::checkParticularJob($userId, $oneJob);
                            if (($jobChecker != 'userCanNotAcceptJob')) {
                                if ($this->isNeedToDelayPush( $oneUser->id )) {
                                    $delpayTranslatorData[] = $oneUser;
                                } 
                                else {
                                    $translatorData[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = '';
        if ($data['immediate'] == 'no') {
            $msg_contents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        } 
        else {
            $msg_contents = 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        }
        $msg_text = array(
            "en" => $msg_contents
        );

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path(env('LOG_FILE') . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translatorData, $delpayTranslatorData, $msg_text, $data]);
        /* Send Notifications */
        $this->sendPushNotificationToSpecificUsers( $translatorData, $job->id, $data, $msg_text, false );       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers( $delpayTranslatorData, $job->id, $data, $msg_text, true ); // send new booking push to suitable translators(need to delay)
    }
    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job){
        $translators   = $this->getPotentialTranslators( $job );
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city  = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate    = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);
        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        // analyse weather it's phone or physical; if both = default to phone
        $message = '';
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            $message = $physicalJobMessageTemplate; // It's a physical job
        } 
        else if ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
            $message = $phoneJobMessageTemplate;   // It's a phone job
        } 
        else if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
            $message = $phoneJobMessageTemplate; // It's both, but should be handled as phone job
        } 
        Log::info($message);
        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = SendSMSHelper::send( env('SMS_NUMBER'), $translator->mobile, $message );
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }
        return count( $translators );
    }
    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param integer $users
     * @param integer $jobID
     * @param array $data
     * @param string $msg_text
     * @param bool $isNeedDelay
     */
    public function sendPushNotificationToSpecificUsers($users, $jobID,array $data, string $msgText, $isNeedDelay){

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path( env('LOG_FILE') . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());

        $logger->addInfo('Push send for job ' . $jobID, [$users, $data, $msgText, $isNeedDelay]);
        if (env('APP_ENV') == 'prod') {
            $onesignalAppID         = config( 'app.prodOnesignalAppID' );
            $onesignalRestAuthKey   = sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'));
        } 
        else {
            $onesignalAppID         = config( 'app.devOnesignalAppID' );
            $onesignalRestAuthKey   = sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));
        }
        $userTags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $jobID;
        $iosSound       = 'default';
        $androidSound   = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $androidSound  = 'normal_booking';
                $iosSound       = 'normal_booking.mp3';
            } 
            else {
                $androidSound  = 'emergency_booking';
                $iosSound      = 'emergency_booking.mp3';
            }
        }

        $fields = array(
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode( $userTags ),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msgText,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $androidSound,
            'ios_sound'      => $ios_sound
        );
        if ($isNeedDelay) {
            $nextBusinessTime       = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after']   = $nextBusinessTime;
        }
        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $jobID . ' curl answer', [$response]);
        curl_close($ch);
    }
    /**
     * @param $job
     * @param $currentTranslator
     * @param $newTranslator
     */
    public function sendChangedTranslatorNotification($job, $currentTranslator, $newTranslator){
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        }
        else {
            $email = $user->email;
        }
        $name       = $user->name;
        $subject    = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data       = ['user' => $user,'job'  => $job ];
        $this->mailer->send( $email, $name, $subject, 'emails.job-changed-translator-customer', $data );
        if ($currentTranslator) {
            $user = $currentTranslator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;
            $this->mailer->send( $email, $name, $subject, 'emails.job-changed-translator-old-translator', $data );
        }
        $user   = $newTranslator->user;
        $name   = $user->name;
        $email  = $user->email;
        $data['user'] = $user;
        $this->mailer->send( $email, $name, $subject, 'emails.job-changed-translator-new-translator', $data );
    }

    /**
     * @param $job
     * @param $oldTime
     */
    public function sendChangedDateNotification($job, $oldTime){
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } 
        else{
            $email = $user->email;
        }
        $name       = $user->name;
        $subject    = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data       = ['user'     => $user,'job'      => $job, 'old_time' => $oldTime];

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = ['user' => $translator,'job' => $job,'old_time' => $oldTime];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * @param $job
     * @param $oldLang
     */
    public function sendChangedLangNotification($job, $oldLang){
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } 
        else {
            $email = $user->email;
        }
        $name       = $user->name;
        $subject    = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data       = ['user'     => $user, 'job'      => $job,'old_lang' => $oldLang];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }
    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user){
        $data = [];
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msgText  = array("en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.');
        if ($this->isNeedToSendPush($user->id)) {
            $usersArray = array($user);
            $this->sendPushNotificationToSpecificUsers($usersArray, $job->id, $data, $msgText, $this->isNeedToDelayPush($user->id));
        }
    }
    /**
     * Function to send the notification for sending the admin job cancel
     * @param $jobID
     */
    public function sendNotificationByAdminCancelJob($jobID){
        $data       = []; 
        $job        = Job::findOrFail($jobID);
        $user_meta  = $job->user->userMeta()->first();
        $data['job_id']             = $job->id;
        $data['from_language_id']   = $job->from_language_id;
        $data['immediate']          = $job->immediate;
        $data['duration']           = $job->duration;
        $data['status']             = $job->status;
        $data['gender']             = $job->gender;
        $data['certified']          = $job->certified;
        $data['due']                = $job->due;
        $data['job_type']           = $job->job_type;
        $data['customer_phone_type']= $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;

        $dueDate = explode(" ", $job->due);
        $dueDate = $dueDate[0];
        $dueTime = $dueDate[1];
        $data['due_date']  = $dueDate;
        $data['due_time']  = $dueTime;
        $data['job_for']   = [];

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
        $this->sendNotificationTranslator( $job, $data, '*' );   // send Push all sutiable translators
    }
    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration){
        $data = [];
        $data['notification_type'] = 'session_start_remind';
        if ($job->customer_physical_type == 'yes')
            $msgText = array( "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!');
        else
            $msgText = array("en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!' );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users, $job->id, $data, $msgText, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }
    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users){
        $userTags   = "[";
        $first      = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } 
            else {
                $userTags .= ',{"operator": "OR"},';
            }
            $userTags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $userTags .= ']';
        return $userTags;
    }
    /**
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     * @return mixed
     */
    public function sendSessionStartRemindNotification($user,string $job, string $language,string $due, $duration){
        $data = [];
        $data['notification_type']  = 'session_start_remind';

        $this->logger->pushHandler(new StreamHandler(storage_path(env('LOG_FILE') . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        
        $due_explode                = explode(' ', $due);
        if ($job->customer_physical_type == 'yes'){
            $messageText = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        }
        else{
            $messageText = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        }
        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $usersArray = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($usersArray, $job->id, $data, $messageText, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }
}