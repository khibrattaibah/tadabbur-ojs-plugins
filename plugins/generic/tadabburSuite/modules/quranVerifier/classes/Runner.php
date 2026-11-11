<?php
/**
 * Copyright (c) 2026 Moustafa Mahmoud Mahmoud (مصطفى محمود محمود)
 *                    and Khibrat Taibah for Research and Studies (مكتب خبرات طيبة للبحوث والدراسات)
 * Developer: Moustafa Mahmoud — https://github.com/mostafamr2011 · ORCID 0009-0008-0991-3443
 * Publisher: Khibrat Taibah — https://khibrattaibah.com
 * Distributed under the GNU General Public License v3 or later. See LICENSE and AUTHORS.md.
 */
/**
 * @file plugins/generic/quranVerifier/classes/Runner.php
 * تشغيل التدقيق على ملف من ملفات البحث وحفظ التقرير.
 */
namespace APP\plugins\generic\tadabburSuite\m\quranVerifier\classes;

use APP\facades\Repo;
use PKP\submissionFile\SubmissionFile;

class Runner
{
    public function __construct(private $plugin) {}

    /** ملفات البحث القابلة للفحص (الأحدث أولًا، وملفات Word قبل PDF) @return array<int, array{id:int,name:string,stage:int,ext:string}> */
    public function candidateFiles(int $submissionId): array
    {
        $out = [];
        $files = Repo::submissionFile()->getCollector()->filterBySubmissionIds([$submissionId])->getMany();
        foreach ($files as $sf) {
            $name = (string) $sf->getLocalizedData('name');
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['docx', 'pdf', 'odt', 'txt', 'html', 'htm'], true)) continue;
            $out[] = ['id' => (int) $sf->getId(), 'name' => $name, 'stage' => (int) $sf->getData('fileStage'), 'ext' => $ext, 'updated' => (string) $sf->getData('updatedAt')];
        }
        $rank = fn ($f) => ($f['ext'] === 'docx' ? 0 : ($f['ext'] === 'odt' ? 1 : ($f['ext'] === 'pdf' ? 2 : 3)));
        usort($out, fn ($a, $b) => [$rank($a), $b['updated'], $b['id']] <=> [$rank($b), $a['updated'], $a['id']]);
        return $out;
    }

    /** نص ملف من ملفات البحث */
    public function fileText(int $submissionFileId, int $submissionId): array
    {
        $sf = Repo::submissionFile()->get($submissionFileId);
        if (!$sf || (int) $sf->getData('submissionId') !== $submissionId) return ['text' => '', 'error' => 'nofile', 'name' => ''];
        $name = (string) $sf->getLocalizedData('name');
        $fileSvc = app()->get('file');
        $file = $fileSvc->get((int) $sf->getData('fileId'));
        $path = (string) $sf->getData('path');
        $tmp = tempnam(sys_get_temp_dir(), 'qvf');
        try {
            file_put_contents($tmp, $fileSvc->fs->read($path));
            $r = TextExtractor::fromFile($tmp, $name, (string) ($file->mimetype ?? ''));
        } catch (\Throwable $e) {
            $r = ['text' => '', 'error' => 'read', 'kind' => ''];
        } finally {
            @unlink($tmp);
        }
        $r['name'] = $name;
        return $r;
    }

    /** يحلّل ويحفظ. @return array{id:int, error:?string} */
    public function runForSubmission($submission, ?int $fileId, ?int $userId, bool $auto = false): array
    {
        $sid = (int) $submission->getId(); $cid = (int) $submission->getData('contextId');
        if (!$fileId) {
            $cands = array_values(array_filter($this->candidateFiles($sid), fn ($f) => $f['stage'] === SubmissionFile::SUBMISSION_FILE_SUBMISSION)) ?: $this->candidateFiles($sid);
            if (!$cands) return ['id' => 0, 'error' => 'nofile'];
            $fileId = $cands[0]['id'];
        }
        $ft = $this->fileText($fileId, $sid);
        if ($ft['error']) return ['id' => 0, 'error' => $ft['error']];
        $result = (new QuranEngine())->analyze($ft['text']);
        $result['chars'] = mb_strlen($ft['text']);
        $id = (new Store())->add([
            'context_id' => $cid, 'submission_id' => $sid, 'submission_file_id' => $fileId, 'file_name' => mb_substr($ft['name'], 0, 250),
            'user_id' => $userId, 'auto' => $auto ? 1 : 0, 'created_at' => date('Y-m-d H:i:s'),
            'stats' => json_encode($result['stats']), 'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        return ['id' => $id, 'error' => null];
    }
}
