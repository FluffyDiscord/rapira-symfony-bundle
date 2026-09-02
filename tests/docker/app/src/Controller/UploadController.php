<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UploadController
{
    #[Route('/upload', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return new Response('no file', Response::HTTP_BAD_REQUEST);
        }

        $target = sys_get_temp_dir() . '/moved-' . uniqid();
        $file->move(dirname($target), basename($target));
        $movedSize = filesize($target);
        @unlink($target);

        return new Response('name=' . $file->getClientOriginalName() . ' size=' . $movedSize);
    }
}
