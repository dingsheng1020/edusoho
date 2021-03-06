<?php

namespace AppBundle\Controller\AdminV2\Teach;

use AppBundle\Common\ExportHelp;
use AppBundle\Common\ArrayToolkit;
use AppBundle\Common\Paginator;
use AppBundle\Controller\AdminV2\BaseController;
use Biz\Activity\Service\ActivityService;
use Biz\Course\Service\CourseNoteService;
use Biz\Course\Service\CourseService;
use Biz\Course\Service\CourseSetService;
use Biz\Task\Service\TaskResultService;
use Biz\Task\Service\TaskService;
use Biz\Taxonomy\Service\CategoryService;
use Biz\Testpaper\Service\TestpaperService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Encoder\MessageDigestPasswordEncoder;

class CourseController extends BaseController
{
    public function coursesDataAction(Request $request, $courseSetId)
    {
        $courseSet = $this->getCourseSetService()->tryManageCourseSet($courseSetId);

        $courses = $this->getCourseService()->findCoursesByCourseSetId($courseSetId);
        $courseId = $request->query->get('courseId');

        if (empty($courseId)) {
            $course = reset($courses);
            $courseId = $course['id'];
        }
        $tasks = $this->getTaskService()->findTasksFetchActivityByCourseId($courseId);

        usort($tasks, function ($a, $b) {
            return $a['id'] > $b['id'];
        });

        $tasks = $this->taskDataStatistics($tasks);

        return $this->render(
            'admin-v2/teach/course-set/course-list-data-modal.html.twig',
            array(
                'tasks' => $tasks,
                'courseSet' => $courseSet,
                'courses' => $courses,
                'courseId' => $courseId,
            )
        );
    }

    public function prepareForExportTasksDatasAction(Request $request, $courseId)
    {
        if (empty($courseId)) {
            return $this->createJsonResponse(array('error' => 'courseId can not be null'));
        }

        list($start, $limit, $exportAllowCount) = ExportHelp::getMagicExportSetting($request);

        list($title, $lessons, $courseTasksCount) = $this->getExportTasksDatas(
            $courseId,
            $start,
            $limit,
            $exportAllowCount
        );

        $file = '';
        if (0 == $start) {
            $file = ExportHelp::addFileTitle($request, 'course_tasks', $title);
        }

        $datas = implode("\r\n", $lessons);
        $fileName = ExportHelp::saveToTempFile($request, $datas, $file);

        $method = ExportHelp::getNextMethod($start + $limit, $courseTasksCount);

        return $this->createJsonResponse(
            array(
                'method' => $method,
                'fileName' => $fileName,
                'start' => $start + $limit,
            )
        );
    }

    public function exportTaskDatasAction(Request $request, $courseId)
    {
        $course = $this->getCourseService()->tryManageCourse($courseId);

        if (empty($course)) {
            return $this->createJsonResponse(array('error' => 'course can not be found'));
        }
        $courseSet = $this->getCourseSetService()->getCourseSet($course['courseSetId']);

        $courseTitle = 1 == $course['isDefault'] ? $courseSet['title'] : $courseSet['title'].'-'.$course['title'];
        $fileName = sprintf('%s-(%s).csv', $courseTitle, date('Y-n-d'));

        return ExportHelp::exportCsv($request, $fileName);
    }

    public function checkPasswordAction(Request $request)
    {
        if ('POST' == $request->getMethod()) {
            $password = $request->request->get('password');
            $currentUser = $this->getUser();
            $password = $this->getPasswordEncoder()->encodePassword($password, $currentUser->salt);

            if ($password == $currentUser->password) {
                $response = array('success' => true, 'message' => '密码正确');
                $request->getSession()->set('checkPassword', true);
            } else {
                $response = array('success' => false, 'message' => '密码错误');
            }

            return $this->createJsonResponse($response);
        }
    }

    public function noteListAction(Request $request)
    {
        $conditions = $request->query->all();

        if (isset($conditions['keywordType']) && 'courseTitle' == $conditions['keywordType']) {
            $courseSets = $this->getCourseSetService()->findCourseSetsLikeTitle($conditions['keyword']);
            $conditions['courseSetIds'] = ArrayToolkit::column($courseSets, 'id');
            unset($conditions['keywordType'], $conditions['keyword']);
            $conditions['courseSetIds'] = $conditions['courseSetIds'] ?: array(-1);
        }

        $paginator = new Paginator(
            $request,
            $this->getNoteService()->countCourseNotes($conditions),
            20
        );
        $notes = $this->getNoteService()->searchNotes(
            $conditions,
            array('createdTime' => 'DESC'),
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );
        $users = $this->getUserService()->findUsersByIds(ArrayToolkit::column($notes, 'userId'));
        $courses = $this->getCourseService()->findCoursesByIds(ArrayToolkit::column($notes, 'courseId'));
        $courseSets = $this->getCourseSetService()->findCourseSetsByIds(ArrayToolkit::column($notes, 'courseSetId'));
        $tasks = $this->getTaskService()->findTasksByIds(ArrayToolkit::column($notes, 'taskId'));

        return $this->render('admin-v2/teach/course-note/index.html.twig', array(
            'notes' => $notes,
            'paginator' => $paginator,
            'users' => $users,
            'tasks' => ArrayToolkit::index($tasks, 'id'),
            'courses' => $courses,
            'courseSets' => $courseSets,
        ));
    }

    public function deleteNoteAction(Request $request, $id)
    {
        $note = $this->getNoteService()->deleteNote($id);

        return $this->createJsonResponse(true);
    }

    public function batchDeleteNoteAction(Request $request)
    {
        $ids = $request->request->get('ids', array());
        $this->getNoteService()->deleteNotes($ids);

        return $this->createJsonResponse(true);
    }

    public function searchToFillBannerAction(Request $request)
    {
        return $this->searchFuncUsedBySearchActionAndSearchToFillBannerAction(
            $request,
            'admin-v2/teach/course/search-to-fill-banner.html.twig'
        );
    }

    protected function searchFuncUsedBySearchActionAndSearchToFillBannerAction(Request $request, $twigToRender)
    {
        $key = $request->query->get('key');

        $conditions = array('title' => $key);
        $conditions['status'] = 'published';
        $conditions['type'] = 'normal';
        $conditions['parentId'] = 0;

        $count = $this->getCourseService()->searchCourseCount($conditions);

        $paginator = new Paginator($this->get('request'), $count, 6);

        $courses = $this->getCourseService()->searchCourses(
            $conditions,
            null,
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );

        $categories = $this->getCategoryService()->findCategoriesByIds(ArrayToolkit::column($courses, 'categoryId'));

        $users = $this->getUserService()->findUsersByIds(ArrayToolkit::column($courses, 'userId'));

        return $this->render($twigToRender, array(
            'key' => $key,
            'courses' => $courses,
            'users' => $users,
            'categories' => $categories,
            'paginator' => $paginator,
        ));
    }

    protected function getExportTasksDatas($courseId, $start, $limit, $exportAllowCount)
    {
        $this->getCourseService()->tryManageCourse($courseId);

        $conditions = array(
            'courseId' => $courseId,
        );
        $courseTasksCount = $this->getTaskService()->countTasks($conditions);

        $courseTasksCount = ($courseTasksCount > $exportAllowCount) ? $exportAllowCount : $courseTasksCount;

        $titles = '任务名,学习人数,完成人数,平均学习时长(分),音视频时长(分),音视频平均观看时长(分),测试平均得分';

        $originaTasks = $this->makeTasksDatasByCourseId($courseId, $start, $limit);

        $exportTasks = array();
        foreach ($originaTasks as $task) {
            $exportTask = '';

            if ('text' == $task['type']) {
                $exportTask .= $task['title'] ? $task['title'].'(图文),' : '-'.',';
            } elseif ('video' == $task['type']) {
                $exportTask .= $task['title'] ? $task['title'].'(视频),' : '-'.',';
            } elseif ('audio' == $task['type']) {
                $exportTask .= $task['title'] ? $task['title'].'(音频),' : '-'.',';
            } elseif ('testpaper' == $task['type']) {
                $exportTask .= $task['title'] ? $task['title'].'(试卷),' : '-'.',';
            } elseif ('ppt' == $task['type']) {
                $exportTask .= $task['title'] ? $task['title'].'(ppt),' : '-'.',';
            } else {
                $exportTask .= $task['title'] ? $task['title'].',' : '-'.',';
            }

            $exportTask .= $task['studentNum'] ? $task['studentNum'].',' : '-'.',';
            $exportTask .= $task['finishedNum'] ? $task['finishedNum'].',' : '-'.',';

            $studentNum = (int) $task['studentNum'];
            $learnedTime = $studentNum ? floor((int) $task['learnedTime'] / $studentNum) : (int) $task['learnedTime'];
            $watchTime = empty($task['watchTime']) ? '' : ($studentNum ? floor((int) $task['watchTime'] / $studentNum) : (int) $task['watchTime']);

            $exportTask .= $learnedTime ? $learnedTime.',' : '-'.',';

            $exportTask .= !empty($task['length']) ? $task['length'].',' : '-'.',';

            $exportTask .= $watchTime ? $watchTime.',' : '-'.',';

            $exportTask .= !empty($task['score']) ? $task['score'].',' : '-'.',';

            $exportTasks[] = $exportTask;
        }

        return array($titles, $exportTasks, $courseTasksCount);
    }

    protected function makeTasksDatasByCourseId($courseId, $start = 0, $limit = 1000)
    {
        $tasks = $this->getTaskService()->searchTasks(array('courseId' => $courseId), array('id' => 'ASC'), $start, $limit);
        $activityIds = ArrayToolkit::column($tasks, 'activityId');
        $activities = $this->getActivityService()->findActivities($activityIds, true);
        $activities = ArrayToolkit::index($activities, 'id');

        array_walk(
            $tasks,
            function (&$task) use ($activities) {
                $task['activity'] = $activities[$task['activityId']];
            }
        );

        $tasks = $this->taskDataStatistics($tasks);

        return $tasks;
    }

    //统计课程任务数据
    protected function taskDataStatistics($tasks)
    {
        foreach ($tasks as $key => &$task) {
            $finishedNum = $this->getTaskResultService()->countTaskResults(
                array('status' => 'finish', 'courseTaskId' => $task['id'])
            );
            $studentNum = $this->getTaskResultService()->countTaskResults(array('courseTaskId' => $task['id']));
            $learnedTime = $this->getTaskResultService()->getLearnedTimeByCourseIdGroupByCourseTaskId($task['id']);

            if (in_array($task['type'], array('video', 'audio'))) {
                $task['length'] = (int) ($task['length'] / 60);
                $watchTime = $this->getTaskResultService()->getWatchTimeByCourseIdGroupByCourseTaskId($task['id']);
                $task['watchTime'] = round($watchTime / 60);
            }

            if ('testpaper' == $task['type'] && !empty($task['activity'])) {
                $activity = $task['activity'];
                $score = $this->getTestpaperService()->searchTestpapersScore(array('testId' => $activity['ext']['mediaId']));
                $paperNum = $this->getTestpaperService()->searchTestpaperResultsCount(
                    array('testId' => $activity['ext']['mediaId'])
                );

                $task['score'] = 0 == $paperNum ? 0 : intval($score / $paperNum);
            }

            $task['finishedNum'] = $finishedNum;
            $task['studentNum'] = $studentNum;

            $task['learnedTime'] = round($learnedTime / 60);
        }

        return $tasks;
    }

    protected function getPasswordEncoder()
    {
        return new MessageDigestPasswordEncoder('sha256');
    }

    /**
     * @return CategoryService
     */
    protected function getCategoryService()
    {
        return $this->createService('Taxonomy:CategoryService');
    }

    /**
     * @return CourseService
     */
    protected function getCourseService()
    {
        return $this->createService('Course:CourseService');
    }

    /**
     * @return CourseSetService
     */
    protected function getCourseSetService()
    {
        return $this->createService('Course:CourseSetService');
    }

    /**
     * @return TaskService
     */
    protected function getTaskService()
    {
        return $this->createService('Task:TaskService');
    }

    /**
     * @return ActivityService
     */
    protected function getActivityService()
    {
        return $this->createService('Activity:ActivityService');
    }

    /**
     * @return TaskResultService
     */
    protected function getTaskResultService()
    {
        return $this->createService('Task:TaskResultService');
    }

    /**
     * @return TestpaperService
     */
    protected function getTestpaperService()
    {
        return $this->createService('Testpaper:TestpaperService');
    }

    /**
     * @return CourseNoteService
     */
    protected function getNoteService()
    {
        return $this->createService('Course:CourseNoteService');
    }
}
