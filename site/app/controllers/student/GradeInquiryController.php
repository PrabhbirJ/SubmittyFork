<?php

namespace app\controllers\student;

use app\controllers\AbstractController;
use app\models\gradeable\GradedGradeable;
use app\models\Notification;
use app\models\Email;
use app\libraries\response\MultiResponse;
use app\libraries\response\JsonResponse;
use app\libraries\response\WebResponse;
use app\libraries\socket\Client;
use WebSocket;
use RuntimeException;
use Symfony\Component\Routing\Annotation\Route;

class GradeInquiryController extends AbstractController {
    /**
     * @param string $gradeable_id
     */
    #[Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grade_inquiry/new", methods: ["POST"])]
    public function requestGradeInquiry($gradeable_id) {
        $content = $_POST['replyTextArea'] ?? '';
        $submitter_id = $_POST['submitter_id'] ?? '';
        $gc_id = $_POST['gc_id'] == 0 ? null : intval($_POST['gc_id']);

        $user = $this->core->getUser();
        $submitter = $this->core->getQueries()->getSubmitterById($submitter_id);

        if ($submitter === null) {
            return JsonResponse::getFailResponse("Failed to get submitter");
        }

        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return JsonResponse::getFailResponse("Could not find gradeable associated with " . $gradeable_id);
        }

        if (!$gradeable->isGradeInquiryOpen()) {
            return MultiResponse::JsonOnlyResponse(
                JsonResponse::getFailResponse('Grade inquiries are not enabled for this gradeable')
            );
        }

        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $submitter_id);
        if ($graded_gradeable === false) {
            return JsonResponse::getFailResponse("No graded gradeable found for submitter");
        }

        $can_inquiry = $this->core->getAccess()->canI("grading.electronic.grade_inquiry", ['graded_gradeable' => $graded_gradeable]) && $user->accessGrading();
        if (!($graded_gradeable->getSubmitter()->hasUser($user) || $can_inquiry)) {
            return MultiResponse::JsonOnlyResponse(
                JsonResponse::getFailResponse('Insufficient permissions to create grade inquiry')
            );
        }

        try {
            $this->core->getQueries()->insertNewGradeInquiry($graded_gradeable, $user, $content, $gc_id);
            $this->notifyGradeInquiryEvent($graded_gradeable, $gradeable_id, $content, 'new', $gc_id);
            $this->sendSocketMessage(['type' => 'open_grade_inquiry', 'submitter_id' => $submitter_id, 'g_id' => $gradeable_id]);

            return MultiResponse::JsonOnlyResponse(JsonResponse::getSuccessResponse(['type' => 'open_grade_inquiry']));
        }
        catch (\InvalidArgumentException $e) {
            return MultiResponse::JsonOnlyResponse(
                JsonResponse::getFailResponse($e->getMessage())
            );
        }
        catch (\Exception $e) {
            return MultiResponse::JsonOnlyResponse(
                JsonResponse::getErrorResponse($e->getMessage())
            );
        }
    }

    /**
     * @param string $gradeable_id
     * @return MultiResponse|JsonResponse|null null is for tryGetGradeable and tryGetGradedGradeable
     */
    #[Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grade_inquiry/post", methods: ["POST"])]
    public function makeGradeInquiryPost($gradeable_id) {
        $content = str_replace("\r", "", $_POST['replyTextArea']);
        $submitter_id = $_POST['submitter_id'] ?? '';
        $gc_id = $_POST['gc_id'] == 0 ? null : intval($_POST['gc_id']);
        $submitter = $this->core->getQueries()->getSubmitterById($submitter_id);

        if ($submitter === null) {
            return JsonResponse::getFailResponse("Failed to get submitter");
        }

        $user = $this->core->getUser();

        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return null;
        }

        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $submitter_id);
        if ($graded_gradeable === false) {
            return null;
        }

        if (!$graded_gradeable->hasGradeInquiry()) {
            return MultiResponse::JsonOnlyResponse(
                JsonResponse::getFailResponse('Submitter has not made a grade inquiry')
            );
        }

        $can_inquiry = $this->core->getAccess()->canI(
            "grading.electronic.grade_inquiry",
            ['graded_gradeable' => $graded_gradeable]
        );
        if (!($graded_gradeable->getSubmitter()->hasUser($user) || $can_inquiry)) {
            return MultiResponse::JsonOnlyResponse(
                JsonResponse::getFailResponse('Insufficient permissions to make grade inquiry post')
            );
        }

        $grade_inquiry = $graded_gradeable->getGradeInquiryByGcId($gc_id);
        if (is_null($grade_inquiry)) {
            return MultiResponse::JsonOnlyResponse(
                JsonResponse::getFailResponse('Cannot find grade inquiry')
            );
        }
        $grade_inquiry_id = $grade_inquiry->getId();

        try {
            $grade_inquiry_post_id = $this->core->getQueries()->insertNewGradeInquiryPost($grade_inquiry_id, $user->getId(), $content, $gc_id);

            $this->notifyGradeInquiryEvent($graded_gradeable, $gradeable_id, $content, 'reply', $gc_id);
            $this->sendSocketMessage(['type' => 'new_post', 'submitter_id' => $submitter_id,'post_id' => $grade_inquiry_post_id, 'gc_id' => $gc_id, 'g_id' => $gradeable_id]);

            return MultiResponse::JsonOnlyResponse(JsonResponse::getSuccessResponse(['type' => 'new_post']));
        }
        catch (\InvalidArgumentException $e) {
            return MultiResponse::JsonOnlyResponse(
                JsonResponse::getFailResponse($e->getMessage())
            );
        }
        catch (\Exception $e) {
            return MultiResponse::JsonOnlyResponse(
                JsonResponse::getErrorResponse($e->getMessage())
            );
        }
    }

    /**
     * @param string $gradeable_id
     */
    #[Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grade_inquiry/single", methods: ["POST"])]
    public function getSingleGradeInquiryPost($gradeable_id) {
        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->useFooter(false);
        //TODO: look into why these aren't getting sent by websockets
        if (!isset($_POST['submitter_id']) || !isset($_POST['post_id']) || !isset($_POST['gc_id'])) {
            return "";
        }

        $gc_id = intval($_POST['gc_id']);
        $submitter_id = $_POST['submitter_id'];
        $post_id = $_POST['post_id'];

        $gradeable = $this->tryGetGradeable($gradeable_id);
        if (!$gradeable) {
            return "";
        }

        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $submitter_id);
        if (!$graded_gradeable) {
            return "";
        }

        if (!$graded_gradeable->hasGradeInquiry()) {
            return "";
        }

        $submitter = $this->core->getQueries()->getSubmitterById($submitter_id);
        if ($submitter === null) {
            return "";
        }

        $user = $this->core->getUser();
        $can_inquiry = $this->core->getAccess()->canI(
            "grading.electronic.grade_inquiry",
            ['graded_gradeable' => $graded_gradeable]
        ) && $user->accessGrading();
        if (!($graded_gradeable->getSubmitter()->hasUser($user) || $can_inquiry)) {
            return "";
        }

        $grade_inquiry = $graded_gradeable->getGradeInquiryByGcId($gc_id);
        if (is_null($grade_inquiry)) {
            return "";
        }

        $new_post = $this->core->getQueries()->getGradeInquiryPost($post_id);
        if ($new_post === null || $new_post['grade_inquiry_id'] !== $grade_inquiry->getId()) {
            return "";
        }

        return new WebResponse(
            ['submission', 'Homework'],
            'renderSingleGradeInquiryPost',
            $new_post,
            $graded_gradeable
        );
    }

    /**
     * @param string $gradeable_id
     * @return JsonResponse|null null is for tryGetGradeable and tryGetGradedGradeable
     */
    #[Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grade_inquiry/toggle_status", methods: ["POST"])]
    public function changeGradeInquiryStatus($gradeable_id) {
        $content = str_replace("\r", "", $_POST['replyTextArea']);
        $submitter_id = $_POST['submitter_id'] ?? '';
        $gc_id = $_POST['gc_id'] == 0 ? null : intval($_POST['gc_id']);

        $user = $this->core->getUser();

        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return null;
        }

        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $submitter_id);
        if ($graded_gradeable === false) {
            return null;
        }

        if (!$graded_gradeable->hasGradeInquiry()) {
            return JsonResponse::getFailResponse('Submitter has not made a grade inquiry');
        }

        $submitter = $this->core->getQueries()->getSubmitterById($submitter_id);
        if ($submitter === null) {
            return JsonResponse::getFailResponse("Failed to get submitter");
        }

        $can_inquiry = $this->core->getAccess()->canI("grading.electronic.grade_inquiry", ['graded_gradeable' => $graded_gradeable]) && $user->accessGrading();
        if (!($graded_gradeable->getSubmitter()->hasUser($user) || $can_inquiry)) {
            return JsonResponse::getFailResponse('Insufficient permissions to change grade inquiry status');
        }

        $grade_inquiry = $graded_gradeable->getGradeInquiryByGcId($gc_id);
        if (is_null($grade_inquiry)) {
            JsonResponse::getFailResponse('Cannot find grade inquiry');
        }
        // toggle status
        $status = $grade_inquiry->getStatus();
        if ($status == -1) {
            $status = 0;
            $type = 'resolve';
        }
        else {
            $status = -1;
            $type = 'reopen';
        }

        try {
            $grade_inquiry->setStatus($status);
            $this->core->getQueries()->saveGradeInquiry($grade_inquiry);
            if ($content != "") {
                $this->core->getQueries()->insertNewGradeInquiryPost($grade_inquiry->getId(), $user->getId(), $content, $gc_id);
            }

            $this->notifyGradeInquiryEvent($graded_gradeable, $gradeable_id, $content, $type, $gc_id);
            $this->sendSocketMessage(['type' => 'toggle_status', 'submitter_id' => $submitter_id, 'g_id' => $gradeable_id]);

            return JsonResponse::getSuccessResponse(['type' => 'toggle_status']);
        }
        catch (\InvalidArgumentException $e) {
            return JsonResponse::getFailResponse($e->getMessage());
        }
        catch (\Exception $e) {
            return JsonResponse::getErrorResponse($e->getMessage());
        }
    }

    /**
     * @param string $gradeable_id
     * @return MultiResponse
     */
    #[Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grade_inquiry/discussion", methods: ["POST"])]
    public function getGradeInquiryDiscussion($gradeable_id) {
        $submitter_id = $_POST['submitter_id'];

        $gradeable = $this->tryGetGradeable($gradeable_id);
        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $submitter_id);

        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->useFooter(false);
        return MultiResponse::webOnlyResponse(
            new WebResponse(
                ['submission', 'Homework'],
                'showGradeInquiryDiscussion',
                $graded_gradeable,
                true
            )
        );
    }

    /**
     * Helper function to create notification/email content and aggregate recipients
     *
     * @param GradedGradeable $graded_gradeable
     * @param string          $gradeable_id
     * @param string          $content
     * @param string          $type
     * @param int|null        $gc_id
     */
    private function notifyGradeInquiryEvent(GradedGradeable $graded_gradeable, $gradeable_id, $content, $type, $gc_id) {
        $component = "";
        $component_title = "";
        $component_string = "";
        if ($graded_gradeable->hasTaGradingInfo()) {
            $ta_graded_gradeable = $graded_gradeable->getOrCreateTaGradedGradeable();
            $graders = $ta_graded_gradeable->getVisibleGraders();
            $submitter = $graded_gradeable->getSubmitter();
            $user_id = $this->core->getUser()->getId();
            $gradeable_title = $graded_gradeable->getGradeable()->getTitle();
            $graders = [];
            if (!is_null($gc_id)) {
                $component = $graded_gradeable->getGradeable()->getComponent($gc_id);
                $component_title = $component->getTitle();
                $component_string = " and for component, $component_title";

                $graded_component_containers = $graded_gradeable->getTaGradedGradeable()->getGradedComponentContainers();
                foreach ($graded_component_containers as $graded_component_container) {
                    if ($graded_component_container->getComponent()->getId() == $gc_id) {
                        $graders = $graded_component_container->getVisibleGraders();
                    }
                }
            }
            else {
                $graders = $ta_graded_gradeable->getGraders();
            }

            if ($type == 'new') {
                if ($this->core->getUser()->accessGrading()) {
                    $subject = "New Grade Inquiry: $gradeable_title - $user_id";
                    $body = "An Instructor/TA/Mentor submitted a grade inquiry for gradeable, $gradeable_title$component_string.\n\n$user_id writes:\n$content";
                }
                else {
                    $subject = "New Grade Inquiry: $gradeable_title - $user_id";
                    $body = "A student has submitted a grade inquiry for gradeable, $gradeable_title$component_string.\n\n$user_id writes:\n$content";
                }
            }
            elseif ($type == 'reply') {
                if ($this->core->getUser()->accessGrading()) {
                    $subject = "New Grade Inquiry Reply: $gradeable_title - $user_id";
                    $body = "An Instructor/TA/Mentor made a post in a grade inquiry for gradeable, $gradeable_title$component_string.\n\n$user_id writes:\n$content";
                }
                else {
                    $subject = "New Grade Inquiry Reply: $gradeable_title - $user_id";
                    $body = "A student has made a post in a grade inquiry for gradeable, $gradeable_title$component_string.\n\n$user_id writes:\n$content";
                }
            }
            elseif ($type == 'resolve') {
                if ($this->core->getUser()->accessGrading()) {
                    $included_post_content = !empty($content) ? "$user_id writes:\n$content" : "";
                    $subject = "Grade Inquiry Resolved: $gradeable_title - $user_id";
                    $body = "An Instructor/TA/Mentor has resolved your grade inquiry for gradeable, $gradeable_title$component_string.\n\n$included_post_content";
                }
                else {
                    $included_post_content = !empty($content) ? "$user_id writes:\n$content" : "";
                    $subject = "Grade Inquiry Resolved: $gradeable_title - $user_id";
                    $body = "A student has cancelled a grade inquiry for gradeable, $gradeable_title$component_string.\n\n$included_post_content";
                }
            }
            elseif ($type == 'reopen') {
                if ($this->core->getUser()->accessGrading()) {
                    $included_post_content = !empty($content) ? "$user_id writes:\n$content" : "";
                    $subject = "Grade Inquiry Reopened: $gradeable_title - $user_id";
                    $body = "An Instructor/TA/Mentor has reopened your grade inquiry for gradeable, $gradeable_title$component_string.\n\n$included_post_content";
                }
                else {
                    $included_post_content = !empty($content) ? "$user_id writes:\n$content" : "";
                    $subject = "Grade Inquiry Reopened: $gradeable_title - $user_id";
                    $body = "A student has reopened a grade inquiry for gradeable, $gradeable_title$component_string.\n\n$included_post_content";
                }
            }
            else {
                throw new RuntimeException("Invalid grade inquiry event type: {$type}");
            }

            $emails = [];
            // make graders' notifications and emails
            $metadata = json_encode(['url' => $this->core->buildCourseUrl(['gradeable', $gradeable_id, 'grading', 'grade?' . http_build_query(['who_id' => $submitter->getId()])])]);
            if (empty($graders)) {
                $graders = $this->core->getQueries()->getAllGraders();
            }
            foreach ($graders as $grader) {
                if ($grader->accessFullGrading()) {
                    $details = ['component' => 'grading', 'metadata' => $metadata, 'body' => $body, 'subject' => $subject, 'sender_id' => $user_id, 'to_user_id' => $grader->getId()];
                    $notifications[] = Notification::createNotification($this->core, $details);
                    $emails[] = new Email($this->core, $details);
                }
            }

            // make students' notifications and emails
            $metadata = json_encode(['url' => $this->core->buildCourseUrl(['gradeable', $gradeable_id])]);
            $notifications = [];
            if ($submitter->isTeam()) {
                $submitting_team = $submitter->getTeam()->getMemberUsers();
                foreach ($submitting_team as $submitting_user) {
                    $details = ['component' => 'student', 'metadata' => $metadata, 'content' => $body, 'body' => $body, 'subject' => $subject, 'sender_id' => $user_id, 'to_user_id' => $submitting_user->getId()];
                    $notifications[] = Notification::createNotification($this->core, $details);
                    $emails[] = new Email($this->core, $details);
                }
            }
            else {
                $details = ['component' => 'student', 'metadata' => $metadata, 'content' => $body, 'body' => $body, 'subject' => $subject, 'sender_id' => $user_id, 'to_user_id' => $submitter->getId()];
                $notifications[] = Notification::createNotification($this->core, $details);
                $emails[] = new Email($this->core, $details);
            }
            $this->core->getNotificationFactory()->sendNotifications($notifications);
            if ($this->core->getConfig()->isEmailEnabled()) {
                $this->core->getNotificationFactory()->sendEmails($emails);
            }
        }
    }

    /**
     * this function opens a WebSocket client and sends a message with the corresponding update
     * @param array<mixed> $msg_array
     */
    private function sendSocketMessage(array $msg_array): void {
        $msg_array['user_id'] = $this->core->getUser()->getId();
        $msg_array['page'] = $this->core->getConfig()->getTerm() . '-' . $this->core->getConfig()->getCourse() . '-' . $msg_array['g_id'] . '_' . $msg_array['submitter_id'];

        try {
            $client = new Client($this->core);
            $client->json_send($msg_array);
        }
        catch (WebSocket\ConnectionException $e) {
            $this->core->addNoticeMessage("WebSocket Server is down, page won't load dynamically.");
        }
    }
}
