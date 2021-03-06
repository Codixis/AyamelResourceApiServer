<?php

namespace Ayamel\ApiBundle\Controller\V1;

use Ayamel\ResourceBundle\Document\Resource;
use Ayamel\ResourceBundle\Document\ContentCollection;
use Ayamel\ApiBundle\Controller\ApiController;
use Ayamel\ApiBundle\Event\Events;
use Ayamel\ApiBundle\Event\ResourceEvent;
use Ayamel\ApiBundle\Event\ResolveUploadedContentEvent;
use Ayamel\ApiBundle\Event\HandleUploadedContentEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;

class UploadContent extends ApiController
{
    /**
     * Upload content for a resource object.  Note that an upload URL is a one-time-use url.  If uploading content fails
     * for any reason, you must request a new upload url to try again.  The reason for this is that the upload
     * url may or may not handle content directly from an authorized client.  Technically files can be uploaded directly
     * from a user of a client system in order to avoid having to send a file via multiple servers.  Because of this, the library
     * will allow clients to reserve one-time-use urls for sending content, which they can then expose to their internal users
     * as needed.
     *
     * For more specifics about uploading content, make sure to read through the documentation on the
     * [wiki](https://github.com/AmericanCouncils/AyamelResourceApiServer/wiki/Uploading-Content).
     *
     * Content can be provided in one of many formats, refer to the list below:
     *
     * -    Upload a file to be stored by the Ayamel server by providing a file upload via the `file` post field.
     *      Files uploaded in this manner will be automatically scheduled to be transcoded into other web-accessible
     *      formats, if applicable.
     *
     * -    Specify a reference to an original file via a public URI, this can be done via the `uri` post field, or
     *      by passing a JSON object with the `uri` key.  The specified uri will be processed to check for availability.
     *      If the uri is in a custom format known to the Ayamel Resource Library, other resource information may be derived and
     *      added into the resource.
     *
     *          {
     *              "uri": "http://example.com/files/my_video.wmv"
     *          }
     *
     *      You send custom URI's for special providers as well:
     *
     *          {
     *              "uri": "youtube://txqiwrbYGrs"
     *          }
     *
     * -    Specify an array of file references on a remote file server by passing a JSON object with the `remoteFiles` key
     *      containing an array of file objects.  These references are stored exactly as received.  Note that the content of
     *      the `attributes` key is validated depending on the file's `mimeType` property.  TODO: determine proper place to
     *      document file attributes.
     *
     *          {
     *              "remoteFiles": [
     *                  {
     *                      "downloadUri": "http://example.com/files/some_video_original.wmv",
     *                      "mime": "video/x-ms-wmv",
     *                      "mimeType": "video/x-ms-wmv",
     *                      "representation": "original",
     *                      "quality": 1,
     *                      "bytes": 14658,
     *                      "attributes": {
     *                          "duration": 300,
     *                          "frameSize": {"width":720,"height":480},
     *                          "frameRate": 48,
     *                          "bitrate": 44000,
     *                      }
     *                   },
     *                   {
     *                      "downloadUri": "http://example.com/files/transcoded.mp4",
     *                      "mime": "video/mp4",
     *                      "mimeType": "video/mp4",
     *                      "representation": "transcoding",
     *                      "quality": 1,
     *                      "bytes": 9600,
     *                      "attributes": {
     *                          "duration": 300,
     *                          "frameSize": {"width":720,"height":480},
     *                          "frameRate": 48,
     *                          "bitrate": 36000,
     *                      }
     *                   }
     *              ]
     *          }
     *
     *
     * @ApiDoc(
     *      resource=true,
     *      description="Upload resource content.",
     *      output="Ayamel\ResourceBundle\Document\Resource",
     *      filters={
     *          {"name"="_format", "default"="json", "description"="Return format, can be one of xml, yml or json"},
     *          {"name"="replace", "dataType"="boolean", "description"="If true, will delete any previous content associated with the resource before adding new content.", "default"=true}
     *      }
     * )
     *
     * @param string $id
     * @param string $token
     */
    public function executeAction($id, $token)
    {
        //NOTE: There are no auth checks on this route, because a one-time-use token is required.  The assumption is currently that
        //if you have a valid one-time-use upload token, then you were properly authenticated then.  Eventually we'll replace
        //this mechanism with something less tedious.

        //get the resource
        $resource = $this->getRequestedResourceById($id, true);

        //check for deleted resource
        if ($resource->isDeleted()) {
            return $this->returnDeletedResource($resource);
        }

        //collections can't contain content
        if ('collection' === $resource->getType()) {
            throw $this->createHttpException(400, "Resource of type [collection] cannot contain their own content, they may only contain Relations.");
        }

        //sequences cannot contain content either
        if ($resource->getSequence()) {
            throw $this->createHttpException(400, "Resource sequences cannot contain their own content, they may only contain Relations.");
        }

        //get the upload token manager
        $tm = $this->container->get('ayamel.api.upload_token_manager');

        //use the upload token, if using the token fails, 401
        try {
            $tm->useTokenForId($id, $token);
        } catch (\Exception $e) {
            $tm->removeTokenForId($id);
            throw $this->createHttpException(401, $e->getMessage());
        }
        $tm->removeTokenForId($id);

        //make sure the resource isn't currently being processed by something
        if (Resource::STATUS_PROCESSING === $resource->getStatus()) {
            throw $this->createHttpException(423, "Resource content is currently being processed, try modifying the content later.");
        }

        $lockKey = $resource->getId()."_upload_lock";
        //TODO: check for (cached) resource lock, throw 423 if present
        //TODO: lock resource

        //get the api event dispatcher
        $apiDispatcher = $this->container->get('event_dispatcher');

        //notify system to resolve uploaded content from the request
        $request = $this->getRequest();

        //determine whether or not to remove previous resource content
        $removePreviousContent = ('true' === $request->query->get('replace', 'true'));

        try {
            //dispatch the resolve event
            $resolveEvent = $apiDispatcher->dispatch(Events::RESOLVE_UPLOADED_CONTENT, new ResolveUploadedContentEvent($resource, $request, $removePreviousContent));
        } catch (\Exception $e) {
            //TODO: unlock resource
            throw ($e instanceof HttpException) ? $e : $this->createHttpException(500, $e->getMessage());
        }
        $contentType = $resolveEvent->getContentType();
        $contentData = $resolveEvent->getContentData();

        //if we weren't able to resolve incoming content, it must be a bad request
        if (false === $contentData) {
            //TODO: unlock resource
            throw $this->createHttpException(422, "Could not resolve valid content.");
        }

        //notify system to handle uploaded content however is necessary and modify the resource accordingly
        try {
            //notify system old content removal if necessary
            if ($resolveEvent->getRemovePreviousContent()) {
                if (!isset($resource->content)) {
                    $resource->content = new ContentCollection;
                }
                $apiDispatcher->dispatch(Events::REMOVE_RESOURCE_CONTENT, new ResourceEvent($resource));
                $resource->content = new ContentCollection;
            }

            $handleEvent = $apiDispatcher->dispatch(Events::HANDLE_UPLOADED_CONTENT, new HandleUploadedContentEvent($resource, $contentType, $contentData));
        } catch (\Exception $e) {
            //TODO: unlock resource
            throw ($e instanceof HttpException) ? $e : $this->createHttpException(500, $e->getMessage());
        }

        //if resource was processed, validate, persist it, and notify the system that a resource has changed
        if ($handleEvent->isResourceModified()) {

            //validate it
            $errors = $this->container->get('validator')->validate($resource);
            if (0 != count($errors)) {
                throw $this->createHttpException(500, implode('; ', iterator_to_array($errors)));
            }

            try {
                //persist it
                $resource = $handleEvent->getResource();
                $manager = $this->get('doctrine_mongodb')->getManager();
                $manager->persist($resource);
                $manager->flush();

                //notify system
                $apiDispatcher->dispatch(Events::RESOURCE_MODIFIED, new ResourceEvent($resource));
            } catch (\Exception $e) {

                //TODO: unlock resource
                throw $this->createHttpException(500, $e->getMessage());
            }
        } else {
            //TODO: unlock resource
            throw $this->createHttpException(422, "The content was not processed, thus the resource was not modified.");
        }

        //TODO: unlock resource

        //return 202 on success
        $code = ($resource->getStatus() === Resource::STATUS_NORMAL) ? 200 : 202;

        return $this->createServiceResponse(array('resource' => $resource), $code);
    }
}
