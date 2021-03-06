<?php

function startsWith($haystack, $needle)
{
    $length = strlen($needle);
    return (substr($haystack, 0, $length) === $needle);
}

function endsWith($haystack, $needle)
{
    $length = strlen($needle);
    if ($length == 0) {
        return true;
    }

    return (substr($haystack, -$length) === $needle);
}

function analyze_url($url)
{
    $description = null;
    $embed = null;

    $url_headers = get_headers($url, 1);
    if (isset($url_headers['Content-Type']) && !is_array($url_headers['Content-Type'])) {
        $type = strtolower($url_headers['Content-Type']);

        $valid_image_type = array();
        $valid_image_type['image/png'] = '';
        $valid_image_type['image/jpg'] = '';
        $valid_image_type['image/jpeg'] = '';
        $valid_image_type['image/jpe'] = '';
        $valid_image_type['image/gif'] = '';
        $valid_image_type['image/tif'] = '';
        $valid_image_type['image/tiff'] = '';
        $valid_image_type['image/svg'] = '';
        $valid_image_type['image/ico'] = '';
        $valid_image_type['image/icon'] = '';
        $valid_image_type['image/x-icon'] = '';

        if (isset($valid_image_type[$type])) {
            $embed = '<img src="'.$url.'" style="max-height:315px;"/>';
            return [$url,$description,$embed];
        }
    }

    $parsed = parse_url($url);
    $segment = explode('/', $parsed['path']);
    if (!isset($segment[1])) {
        $segment[1] = '';
    }
    if (!isset($segment[2])) {
        $segment[2] = '';
    }

    $CI =& get_instance();
    $CI->load->library("simple_html_dom");
    $html = new Simple_html_dom();
    $html->load(using_curl($url));

    if ($html->find('meta[property=og:description]')) {
        $description = trim(str_replace(array('&#039;','&#39;'), "'", $html->find('meta[property=og:description]', 0)->content));
        $description = preg_replace('/[[:^print:]]/', '', $description);
    }

    if (endsWith($parsed['host'], 'youtube.com') || ($parsed['host'] == 'youtu.be')) {
        preg_match("/^.*((youtu.be\/)|(v\/)|(\/u\/\w\/)|(embed\/)|(watch\?))\??v?=?([^#\&\?]*).*/", $url, $id);
        $embed = '<iframe width="560" height="315" src="https://www.youtube.com/embed/'.$id[7].'" frameborder="0" allowfullscreen></iframe>';

        return [$html->find('link[itemprop=thumbnailUrl]', 0)->href,$description,$embed];
    }

    if (endsWith($parsed['host'], 'twitter.com') && ($segment[2] == 'status')) {
        if (startsWith($parsed['host'], 'mobile')) {
            $json = using_curl("https://publish.twitter.com/oembed?url=".'https://twitter.com/'.$segment[1].'/'.$segment[2].'/'.$segment[3]);
        } else {
            $json = using_curl("https://publish.twitter.com/oembed?url=".$url);
        }
        $obj = json_decode($json);
        $embed = $obj->html;
        return ['https://pbs.twimg.com/profile_images/531381005165158401/bUJYaSO9.png',$description,$embed];
    }

    $fb_post_criteria = ['posts','activity','photo.php','photos','permalink.php','media','questions','notes'];
    if (endsWith($parsed['host'], 'facebook.com') && (!empty(array_intersect($segment, $fb_post_criteria)))) {
        $json_url = "https://www.facebook.com/plugins/post/oembed.json/?url=".$url;
        $obj = json_decode(using_curl($json_url));
        $embed = $obj->html;
        return ['https://www.facebook.com/images/fb_icon_325x325.png',$description,$embed];
    }

    $fb_video_criteria = ['videos','video.php'];
    if (endsWith($parsed['host'], 'facebook.com') && (!empty(array_intersect($segment, $fb_video_criteria)))) {
        $json_url = "https://www.facebook.com/plugins/video/oembed.json/?url=".$url;
        $obj = json_decode(using_curl($json_url));
        $embed = $obj->html;
        return ['https://www.facebook.com/images/fb_icon_325x325.png',$description,$embed];
    }

    if ((endsWith($parsed['host'], 'instagram.com') || ($parsed['host'] == 'instagr.am')) && ($segment[1] == 'p')) {
        $json = using_curl("https://api.instagram.com/oembed?url=".$url);
        $obj = json_decode($json);
        $embed = $obj->html;
        $embed = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $embed);
    }

    if (endsWith($parsed['host'], '9gag.com') && ($segment[1] == 'gag')) {
        $handle = curl_init("http://img-9gag-fun.9cache.com/photo/".$segment[2]."_460sv.mp4");
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);

        /* Get the HTML or whatever is linked in $url. */
        $response = curl_exec($handle);

        /* Check for 404 (file not found). */
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if ($httpCode == 404) {
            /* Handle 404 here. */
            $embed = '<img src="http://img-9gag-fun.9cache.com/photo/'.$segment[2].'_460s.jpg"/>';
            return [$html->find('meta[property=og:image]', 0)->content,'',$embed];
        }

        curl_close($handle);

        /* Handle $response here. */
        $embed = '<div class="video-wrapper">
                    <video poster="http://img-9gag-fun.9cache.com/photo/'.$segment[2].'_460s.jpg" loop muted>
                        <source src="http://img-9gag-fun.9cache.com/photo/'.$segment[2].'_460sv.mp4" type="video/mp4" />
                    </video>
                    <div class="playpause"></div>
                </div>';
        return [$html->find('meta[property=og:image]', 0)->content,'',$embed];
    }

    if ($html->find('meta[property=og:image]')) {
        $src = $html->find('meta[property=og:image]', 0)->content;
        if ($src == '') {
        } else {
            if (strpos($src, '://') !== false) {
                $imageurl = $src;
            } elseif (substr($src, 0, 2) === "//") {
                $imageurl = 'http:'.$src;
            } else {
                $imageurl = $parsed['scheme'].'://'.$parsed['host'].'/'.$src;//get image absolute url
            }
            return [$imageurl,$description,$embed];
        }
    }

    $biggestImage = ''; // Is returned when no images are found.
    $maxSize = 0;
    $visited = array();
    $getimagesize_counter = 0;
    $min_edge_length = 200;
    foreach ($html->find('img') as $element) {
        //echo "\nNEW IMAGE:\n";
        $src = $element->src;
        if ($src=='') {
            continue;
        }// it happens on your test url
        if (strpos($src, '://') !== false) {
            $imageurl = $src;
        } elseif (substr($src, 0, 2) === "//") {
            $imageurl = 'http:'.$src;
        } else {
            $imageurl = $parsed['scheme'].'://'.$parsed['host'].'/'.$src;//get image absolute url
        }

        // ignore already seen images, add new images
        if (in_array($imageurl, $visited)) {
            continue;
        }
        $visited[] = $imageurl;

        // get original size of first image occurrence without a width or a height attribute
        if ((empty($element->width) || empty($element->height)) && !($getimagesize_counter > 0)) {
            //echo "Running getimagesize without looking to DOM."."\n";
            $image = @getimagesize($imageurl); // get the rest images width and height
            //echo print_r($image)."\n";
            $getimagesize_counter++;
            if (($image[0] >= $min_edge_length) && ($image[1] >= $min_edge_length)) {
                if ($image[0] > $maxSize) {
                    $maxSize = $image[0];
                    $biggestImage = $imageurl;
                } elseif ($image[1] > $maxSize) {
                    $maxSize = $image[1];
                    $biggestImage = $imageurl;
                }
                //echo "Found by DIRECTLY getimagesize."."\n";
            } else {
                $getimagesize_counter--;
            }
        }

        //echo $element->width."\n";
        //echo $element->height."\n";
        if (($element->width >= $min_edge_length) && ($element->height >= $min_edge_length)) {
            if (($element->width > $maxSize) || ($element->height > $maxSize)) {
                //echo "Found by DOM. Checking by getimagesize..."."\n";
                if (($element->width > $maxSize) || ($element->height > $maxSize)) {
                    $image = @getimagesize($imageurl); // get the rest images width and height
                    //echo print_r($image)."\n";
                    if (($image[0] >= $min_edge_length) && ($image[1] >= $min_edge_length)) {
                        if ($image[0] > $maxSize) {
                            $maxSize = $element->width;
                            $biggestImage = $imageurl;
                        } elseif ($image[1] > $maxSize) {
                            $maxSize = $element->height;
                            $biggestImage = $imageurl;
                        }
                        $getimagesize_counter++;
                        //echo "DOM properties were correct."."\n";
                    } else {
                        //echo "DOM properties were wrong."."\n";
                    }
                }
            }
        }
        //echo "STATE: ".$biggestImage."\n";
        //echo "MAXSIZE: ".$maxSize."\n";
        //echo "COUNTER: ".$getimagesize_counter."\n";
    }
    return [$biggestImage,$description,$embed]; //return the biggest found image
    //return implode(" | ", $visited);
}
