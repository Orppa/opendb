<?php
/* 	
	Open Media Collectors Database
	Copyright (C) 2001,2006 by Jason Pell

	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
*/
include_once("./functions/SitePlugin.class.inc");
include_once("./site/amazonutils.php");

class amazon extends SitePlugin
{
	function amazon($site_type)
	{
		parent::SitePlugin($site_type);
	}

	function queryListing($page_no, $items_per_page, $offset, $s_item_type, $search_vars_r)
	{
		if(strlen($search_vars_r['amazonasin'])>0)
		{
			$this->addListingRow(NULL, NULL, NULL, array('amazonasin'=>$search_vars_r['amazonasin']));
			return TRUE;
		}
		else
		{
			// Get the mapped AMAZON index type
			$index_type = ifempty($this->getConfigValue('item_type_to_index_map', $s_item_type), strtolower($s_item_type));

			$queryUrl = "http://www.amazon.com/exec/obidos/external-search?index=".$index_type."&keyword=".urlencode($search_vars_r['title'])."&sz=$items_per_page&pg=$page_no";
			
			$pageBuffer = $this->fetchURI($queryUrl);
		}

		if(strlen($pageBuffer)>0)
		{
			$amazonasin = FALSE;

			//<li><b>ISBN-10:</b> 0812929985</li>
			// check for an exact match, but not if this is second page of listings or more
			if(!$this->isPreviousPage())
			{
				if (preg_match("/ASIN: <font>(\w{10})<\/font>/", $pageBuffer, $regs))
				{
					$amazonasin = trim($regs[1]);
				}
				else if (preg_match("/ASIN: (\w{10})/", strip_tags($pageBuffer), $regs))
				{
					$amazonasin = trim($regs[1]);
				}
				else if (preg_match ("!<li><b>ISBN-10:</b>\s*([0-9]+)</li>!", $pageBuffer, $regs)) // for books, ASIN is the same as ISBN
				{
					$amazonasin = trim ($regs[1]);
				}
			}

			// exact match
			if($amazonasin!==FALSE)
			{
				// single record returned
				$this->addListingRow(NULL, NULL, NULL, array('amazonasin'=>$amazonasin, 'search.title'=>$search_vars_r['title']));

				return TRUE;
			}
			else
			{
				$pageBuffer = preg_replace('/[\r\n]+/', ' ', $pageBuffer);
			
				//<td class="resultCount">Showing 3 Results</td>
				if(preg_match("/<td class=\"resultCount\">Showing [0-9]+[\s]*-[\s]*[0-9]+ of ([0-9,]+) Results<\/td>/i", $pageBuffer, $regs) || 
						preg_match("/<td class=\"resultCount\">Showing ([0-9]+) Result[s]*<\/td>/i", $pageBuffer, $regs))
				{
					// store total count here.
					$this->setTotalCount($regs[1]);

					// 1 = img, 2 = href, 3 = title					
					if(preg_match_all("!<td class=\"imageColumn\"[^>]*>.*?".
									"<img src=\"([^\"]+)\"[^>]*>".
									".*?".
									"<a href=\"([^\"]+)\"[^>]*><span class=\"srTitle\">([^<]+)</span></a>!m", $pageBuffer, $matches))
					{
						for($i=0; $i<count($matches[0]); $i++)
						{
							//http://www.amazon.com/First-Blood-David-Morrell/dp/0446364401/sr=1-1/qid=1157433908/ref=pd_bbs_1/104-6027822-1371911?ie=UTF8&s=books
							if(preg_match("!/dp/([^/]+)/!", $matches[2][$i], $regs))
							{
								if(strpos($matches[1][$i], "no-img")!==FALSE)
									$matches[1][$i] = NULL;
								
								$this->addListingRow($matches[3][$i], $matches[1][$i], NULL, array('amazonasin'=>$regs[1], 'search.title'=>$search_vars_r['title']));
							}
						}
					}
				}					
			}

			//default
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}

	function queryItem($search_attributes_r, $s_item_type)
	{
		// assumes we have an exact match here
		$pageBuffer = $this->fetchURI("http://www.amazon.com/gp/product/".$search_attributes_r['amazonasin']);
		
		// no sense going any further here.
		if(strlen($pageBuffer)==0)
			return FALSE;

		$pageBuffer = preg_replace('/[\r\n]+/', ' ', $pageBuffer);
		$pageBuffer = preg_replace('/>[\s]*</', '><', $pageBuffer);
		
		if(preg_match("/<span id=\"btAsinTitle\">([^<]+)<\/span>/s", $pageBuffer, $regs) ||
				preg_match("/<b class=\"sans\">([^<]+)<\/b>/s", $pageBuffer, $regs) || 
				preg_match("/<b class=\"sans\">([^<]+)<!--/s", $pageBuffer, $regs))
		{
		    $title = trim($regs[1]);

			// If extra year appended, remove it and just get the title.
			if(preg_match("/(.*)\([0-9]+\)$/", $title, $regs2))
				$title = $regs2[1];
				
			$title = trim(str_replace("\"", "", $title));

			// get rid of Blu ray suffix as pointless
			if(ends_with($title, '[Blu-ray]'))
			{
				$title = substr($title, 0, strlen($title)-strlen('[Blu-ray]'));
			}
			
			$this->addItemAttribute('title', $title);
			
			//Amazon.com: DVD: First Blood (Special Edition) (1982)
			// Need to escape any (, ), [, ], :, .,
			if (preg_match("/".preg_quote($this->getItemAttribute('title'), "/")." \(([0-9]*)\)/s", $pageBuffer, $regs))
			{
				$this->addItemAttribute('year', $regs[1]);
			}
		}

		// a hack!
		$upcId = get_upc_code($search_attributes_r['search.title']);
		if($upcId && $upcId != $this->getItemAttribute('title'))
		{
			$this->addItemAttribute('upc_id', $upcId);
		}

		/*http://ecx.images-amazon.com/images/I/41CJBZZSQFL._AA240_.jpg
		http://ecx.images-amazon.com/images/I/41CJBZZSQFL._SS500_.jpg
		*/		
		if(preg_match("!registerImage\(\"original_image[^\"]*\", \"([^\"]+)\"!", $pageBuffer, $regs))
		{
			$image = str_replace('AA240', 'SS500', $regs[1]);
			$this->addItemAttribute('imageurl', $image);
		}
		
		if(preg_match_all("!registerImage\(\"cust_image[^\"]*\", \"([^\"]+)\"!", $pageBuffer, $regs))
		{
			while(list(,$image) = each($regs[1]))
			{
				$image = str_replace('AA240', 'SS500', $image);
				$this->addItemAttribute('cust_imageurl', $image);
			}
		}
	    
	    //http://www.amazon.com/gp/product/product-description/0007136587/ref=dp_proddesc_0/002-1041562-0884857?ie=UTF8&n=283155&s=books
		if(preg_match("!<a href=\"http://www.amazon.com/gp/product/product-description/".$search_attributes_r['amazonasin']."/[^>]*>See all Editorial Reviews</a>!", $pageBuffer, $regs) ||
				preg_match("!<a href=\"http://www.amazon.com/gp/product/product-description/".$search_attributes_r['amazonasin']."/[^>]*>See all Reviews</a>!", $pageBuffer, $regs))
		{
			$reviewPage = $this->fetchURI("http://www.amazon.com/gp/product/product-description/".$search_attributes_r['amazonasin']."/reviews/");
			if(strlen($reviewPage)>0)
			{
				$reviews = parse_amazon_reviews($reviewPage);
				if(is_not_empty_array($reviews))
				{
					$this->addItemAttribute('blurb', $reviews);
				}	
			}
		}
		else
		{
			$reviews = parse_amazon_reviews($pageBuffer);
			if(is_not_empty_array($reviews))
			{
				$this->addItemAttribute('blurb', $reviews);
			}
		}

		if(preg_match("/<span class=listprice>\\\$([^<]*)<\/span>/i", $pageBuffer, $regs))
		{
			$this->addItemAttribute('listprice', $regs[1]);
		}
		else if(preg_match("/<td class=\"listprice\">\\\$([^<]*)<\/td>/i", $pageBuffer, $regs))
		{
			$this->addItemAttribute('listprice', $regs[1]);
		}
		else if(preg_match("/<b>List Price:<\/b>[^\\$]+\\$([0-9\.]+)/m", $pageBuffer, $regs))
		{
			$this->addItemAttribute('listprice', $regs[1]);
		}

		// amazon price value
		if(preg_match("/<td><b class=\"price\">\\\$([^<]*)<\/b>/i", $pageBuffer, $regs))
		{
			$this->addItemAttribute('price', $regs[1]);
		}
		
		//http://g-ec2.images-amazon.com/images/G/01/x-locale/common/customer-reviews/stars-4-0._V47081936_.gif 
		if(preg_match("!<li><b>Average Customer Review:</b>[\s]*<img src=\".*?/stars-([^\.]+).!i", $pageBuffer, $regs))
		{
			$amazonreview = str_replace('-', '.', $regs[1]);
			$this->addItemAttribute('amznrating', $amazonreview);
			$this->addItemAttribute('amazon_review', $amazonreview);
		}
		
		// Get the mapped AMAZON index type
		$index_type = ifempty($this->getConfigValue('item_type_to_index_map', $s_item_type), strtolower($s_item_type));

		switch($index_type)
		{
			case 'dvd':
			case 'vhs':
				$this->parse_amazon_video_data($search_attributes_r, $s_item_type, $pageBuffer);
				break;

			case 'videogames':
				$this->parse_amazon_game_data($search_attributes_r, $pageBuffer);
				break;

			case 'books':
				$this->parse_amazon_books_data($search_attributes_r, $pageBuffer);
				break;

			case 'music':
				$this->parse_amazon_music_data($search_attributes_r, $pageBuffer);
				break;

			default://Not much here, but what else can we do?
				break;
		}

		return TRUE;
	}

	/**
		Will return an array of the following structure.
			array(
				"gamepblshr"=>game publisher,
				"gamesystem"=>game platform,
				"gamerating"=>esrb rating
				"features"=>features listing for game,
			);
	*/
	function parse_amazon_game_data($search_attributes_r, $pageBuffer)
	{
		//Other products by <a href="/exec/obidos/search-handle-url/002-1041562-0884857?%5Fencoding=UTF8&amp;store-name=videogames&amp;search-type=ss&amp;index=videogames&amp;field-brandtextbin=Electronic%20Arts">Electronic Arts</a>
		// Publisher extraction block
		if (preg_match("/Other products by <a[^<]*>([^<]*)<\/a>/i", $pageBuffer, $regs))
		{
			$this->addItemAttribute('gamepblshr', $regs[1]);
		}

		// Platform extraction block
		if (preg_match("!<b>Platform:</b>[^<]*<img src=\"([^\"]+)\"[^<]*>([^<]+)</div>!mi", $pageBuffer, $regs))
		{
			// Different combo's of windows, lets treat them all as windows.
			if(strpos($regs[2], "Windows")!==FALSE)
				$platform = "Windows";
			else
				$platform = trim($regs[2]);

				$this->addItemAttribute('gamesystem', $platform);
		}

		// Rating extraction block
		if (preg_match("!<b>ESRB Rating:[\s]*</b>.*?<a href=\"[^\"]*\">([^<]+)</a></li>!si", $pageBuffer, $regs))
		{
			$this->addItemAttribute('gamerating', strtoupper($regs[1]));
		}

		// Features extraction block
		if(preg_match("/<b[^<]*>Product Features<\/b>.*?<ul[^<]*>(.+?)<\/ul>/msi", $pageBuffer, $featureblock))
		{
			if(preg_match_all("/<li>([^<]*)<\/li>/si", $featureblock[1], $matches))
			{
				for($i = 0; $i < count($matches[1]); $i++)
				{
					$matches[1][$i] = strip_tags($matches[1][$i]);
				}

				$this->addItemAttribute('features', implode("\n", $matches[1]));
			}
		}
		
		if(preg_match("!<b>Media:[\s]*</b>[\s]*([^<]+)</li>!", $pageBuffer, $regs))
		{
			$this->addItemAttribute('media', $regs[1]);
		}
		
		if (preg_match("!<li><b> Release Date:</b>([^<]*)</li>!si", $pageBuffer, $regs))
		{
			$timestamp = strtotime($regs[1]);
    		$date = date('d/m/Y', $timestamp);
    		$this->addItemAttribute('gamepbdate', $date);
		}
		
		// now parse game plot
		$start = strpos($pageBuffer, "<div class=\"bucket\" id=\"productDescription\">");
		if($start!==FALSE)
			$start = strpos($pageBuffer, "<div class=\"content\">", $start);
		if($start!==FALSE)
			$start = strpos($pageBuffer, "<b>Product Description</b>", $start);
			
		if($start!==FALSE)
		{
			$start += strlen("<b>Product Description</b>");
			$end = strpos($pageBuffer, "</div>", $start);
			$productDescriptionBlock = substr($pageBuffer, $start, $end-$start);
			$this->addItemAttribute('game_plot', $productDescriptionBlock);
		}
	}

	/*
	* 	Parse Amazon.com CD item
	*
	* 	Will return
	* 	Array(
	* 		'artist'=>'',
	* 		'release_dt'=>'',
	* 		'year'=>'',
	* 		'musiclabel'=>'',
	* 		'no_discs'=>'',
	* 		'cdtrack'=>Array(...)
	* 	);
	*/
	function parse_amazon_music_data($search_attributes_r, $pageBuffer)
	{
		//<META name="description" content="Come on Over, Shania Twain, Mercury">
		if(preg_match("/<meta name=\"description\" content=\"([^\"]*)\"/i",$pageBuffer, $regs))
		{
			if(preg_match("/by (.*)/i", $regs[1], $regs2))
			{
				// the artist is the last part of the description.
				// Amazon.fr : Dangerous: Musique: Michael Jackson by Michael Jackson
				$this->addItemAttribute('artist', $regs2[1]);
			}
		}

		//<li><b>Label:</b> Columbia</li>
		//<b>Label:</b> <a HREF="/exec/obidos/search-handle-url/size=20&store-name=music&index=music&field-label=Mercury/026-5027435-0842841">Mercury</a><br>
		if(preg_match("!<b>Label:[\s]*</b>[\s]*([^<]+)</li>!i", $pageBuffer, $regs))
		{
			$this->addItemAttribute('musiclabel', $regs[1]);
		}
		
		//<B> Audio CD </B>
		//(November 18, 2002)<br>
		//<li><b>Audio CD</b>  (2 Oct 2006)</li>
		if(preg_match("!<b>[\s]*Audio CD[\s]*</b>.*\(([^\)]+)\)</li>!sUi", $pageBuffer, $regs))
		{
			$timestamp = strtotime($regs[1]);

			$this->addItemAttribute('release_dt', date('d/m/Y', $timestamp));
    		$this->addItemAttribute('year', date('Y', $timestamp));
		}
		
		//<li><b>Number of Discs:</b> 1</li>
		if(preg_match("!<b>Number of Discs:[\s]*</b>[\s]*([0-9]+)!", $pageBuffer, $regs))
		{
			$this->addItemAttribute('no_discs', $regs[1]);
		}
		
		if(preg_match("!<b>Original Release Date:[\s]*</b>[\s]*([^<]+)<!", $pageBuffer, $regs))
		{
			$timestamp = strtotime($regs[1]);
			$this->addItemAttribute('orig_release_dt', date('d/m/Y', $timestamp));
		}
		
		if(preg_match("!http://www.amazon.com/.*/dp/samples/".$search_attributes_r['amazonasin']."/!", $pageBuffer, $regs))
		{
			$samplesPage = $this->fetchURI("http://www.amazon.com/dp/samples/".$search_attributes_r['amazonasin']."/");
			if(strlen($samplesPage)>0)
			{
				$samplesPage = preg_replace('/[\r\n]+/', ' ', $samplesPage);
				$tracks = parse_music_tracks($samplesPage);
				$this->addItemAttribute('cdtrack', $tracks);
			}
		}
		else if(preg_match("!<div class=\"bucket\">[\s]*<b class=\"h1\">Track Listings</b>(.*?)</div>!", $pageBuffer, $regs) || 
				preg_match("!<div class=\"bucket\">[\s]*<b class=\"h1\">Listen to Samples</b>(.*?)</div>!", $pageBuffer, $regs))
		{
			$tracks = parse_music_tracks($regs[1]);
			$this->addItemAttribute('cdtrack', $tracks);
		}
	}

	/**
		Will return an array of the following structure.
			array(
				"author"=>author,
				"publisher"=>publisher,
				"pub_date"=>date published,
				"isbn"=>ISBN number,
				"listprice"=>Regular price,
			);

		If nothing parsed correctly, then this function will returned
		unitialised array.
	*/
	function parse_amazon_books_data($search_attributes_r, $pageBuffer)
	{
		if(preg_match_all("!<a href=\".*?field-author=[^\"]*\">([^<]*)</a>!i", $pageBuffer, $regs))
		{
			$this->addItemAttribute('author', $regs[1]);
		}
	
		if( ( $startIndex = strpos($pageBuffer, "<b class=\"h1\">Look for similar items by subject</b>") ) !== FALSE &&
				($endIndex = strpos($pageBuffer, "</form>", $startIndex) ) !== FALSE )
		{
			$subjectform = substr($pageBuffer, $startIndex, $endIndex-$startIndex);
			
			if(preg_match_all("!<input type=\"checkbox\" name=\"field\+keywords\" value=\"([^\"]+)\"!", $subjectform, $matches))
			{
				$this->addItemAttribute('genre', $matches[1]);
			}
		}
		
		if(preg_match("!<b>ISBN-10:</b>[\s]*([0-9X]+)!", $pageBuffer, $regs))
		{
			$this->addItemAttribute('isbn', $regs[1]);
			$this->addItemAttribute('isbn10', $regs[1]);
		}
		
		if(preg_match("!<b>ISBN-13:</b>[\s]*([0-9\-]+)!", $pageBuffer, $regs))
		{
			$this->addItemAttribute('isbn13', $regs[1]);
		}

		//<li><b>Paperback:</b> 1500 pages</li>
		if(preg_match("/([0-9]+) pages/", $pageBuffer, $regs))
		{
			$this->addItemAttribute('nb_pages', $regs[1]);
		}
		
		//<li><b>Publisher:</b> Prima Games (November 24, 1998)</li>
		//<li><b>Publisher:</b> HarperCollins; New Ed edition (1 Mar 1999)</li>
		if(preg_match("!<b>Publisher:</b>[\s]*([^;\(]+);!U", $pageBuffer, $regs) || 
				preg_match("!<b>Publisher:</b>[\s]*([^\(]+)\(!U", $pageBuffer, $regs) || 
				preg_match("!<b>Publisher:</b>[\s]*([^<]+)</li>!U", $pageBuffer, $regs)) 
		{
			$this->addItemAttribute('publisher', $regs[1]);
		}
		
		if(preg_match("!<b>Publisher:</b>.*?\(([^\)]*[0-9]+)\)!", $pageBuffer, $regs))
		{
			$timestamp = strtotime($regs[1]);
    		$date = date('Y', $timestamp);
    		$this->addItemAttribute('pub_date', $date);
		}
	}

	/**
		Will return an array of the following structure.
			array(
				"year"=>year,
				"age_rating"=>age_rating,
				"dvd_region"=>dvd_region, // not applicable for VHS,DIVX,etc
				"ratio"=>ration,
				"audio_lang"=>spoken languages,
				"subtitles"=>subtitles,
				"run_time"=>runtime,
				"director"=>director,
				"actors"=>actors,
			);

		If nothing parsed correctly, then this function will returned
		unitialised array.
	*/
	function parse_amazon_video_data($search_attributes_r, $s_item_type, $pageBuffer)
	{
        // All Amazon.com (US) items should be NTSC!
		$this->addItemAttribute('vid_format', 'NTSC');
		
		// genre extraction block.
		$startidx = strpos($pageBuffer, "<li><b>Genres:</b>");
		if($startidx !== FALSE)
		{
			// Move past start text.
			$startidx+=18;//"Genres:</b>"

			$endidx = strpos($pageBuffer,"</li>", $startidx);

			if ($endidx !== FALSE)
			{
				// Get rid of all the html - a quick hack!
				$genre = trim(substr($pageBuffer,$startidx,$endidx-$startidx));
				$genre = strip_tags($genre);

				// If composite genre, get rid of / as we do not need it.
				$genre = str_replace(" / "," ",$genre);

				// Expand Sci-Fi to OpenDb matching value.
				$genre = str_replace("Sci-Fi", "ScienceFiction", $genre);

				// Match all whitespace and convert to a comma.
				$genre = preg_replace("/[\s]+/", ",", $genre);

				$genre = str_replace("(more)","", $genre);

				$this->addItemAttribute('genre', explode(",", $genre));
			}
        }

		$this->addItemAttribute('actors', parse_amazon_video_people("Actors", $pageBuffer));
		$this->addItemAttribute('director', parse_amazon_video_people("Directors", $pageBuffer));

		// Region extraction block
		//<li><b>Region: </b>Region 1
		if (preg_match("/<li><b>Region:[\s]*<\/b>Region ([0-6])/", $pageBuffer, $regs))
		{
			$this->addItemAttribute('dvd_region', $regs[1]);
		}

		// Ratio
		//<li><b>Aspect Ratio:</b> 1.85:1</li>
		if(preg_match("!<li><b>Aspect Ratio:</b>(.*)<\/li>!", $pageBuffer, $regs))
		{
			if(preg_match_all("/([0-9]{1}\.[0-9]+):1/", $regs[1], $matches))
			{
				$this->addItemAttribute('ratio', $matches[1]);
			}
		}

        if(preg_match("/<li><b>Number of discs:[\s]*<\/b>[\s]*([0-9]+)/", $pageBuffer, $regs2))
		{
			$this->addItemAttribute('no_discs', $regs2[1]);
   		}

		//<b>Rating</b>  <img src="http://ec1.images-amazon.com/images/G/01/detail/r._V46905301_.gif" alt="R" align="absmiddle" border="0" height="11" width="12"></li>
		if (preg_match("/<b>Rating:*<\/b>[\s]*<img src=.*? alt=\"([^\"]+)\".*?>/mi", $pageBuffer, $regs))
		{
			$this->addItemAttribute('age_rating', $regs[1]);
		}
		
		if(preg_match("!<b>Studio:[\s]*</b>[\s]*([^<]+)</li>!i", $pageBuffer, $regs))
		{
			$this->addItemAttribute('studio', $regs[1]);
		}

		//<li><b>DVD Release Date:</b> April 27, 2004</li>
		if(preg_match("/<b>DVD Release Date:<\/b>([^<]+)<\/li>/i", $pageBuffer, $regs))
		{
			$timestamp = strtotime($regs[1]);
    		
			// if year not defined, use dvd_rel_dt
			if($this->getItemAttribute('year') === FALSE)
			{
				$this->addItemAttribute('year', date('Y', $timestamp));
			}
				
    		$this->addItemAttribute('dvd_rel_dt', date('d/m/Y', $timestamp));
		}

        // Duration extraction block
		//<li><b>Run Time:</b> 125 minutes </li>
		if (preg_match("/<li><b>Run Time:<\/b>[\s]*([0-9]+) minutes/i", $pageBuffer, $regs))
		{
   			$this->addItemAttribute('run_time', $regs[1]);
		}

        // Get the anamorphic format attribute - Thanks to André Monz <amonz@users.sourceforge.net
		if(preg_match("/anamorphic/",$pageBuffer))
		{
			$this->addItemAttribute('anamorphic', 'Y');
   		}

        if (preg_match("/THX Certified/i", $pageBuffer))
		{
			$this->addItemAttribute('dvd_audio', 'THX');
		}

		if(preg_match("!<li><b>Language:</b>[\s]*(.*?)</li>!i", $pageBuffer, $regs))
		{
			$audio_lang_r = explode(',', $regs[1]);

			$amazon_dvd_audio_map = array(
						array("English", "2.0"),
						array("English", "5.0"),
						array("English", "5.1"),
						array("English", "6.1", "EX"), // Dolby Digital 6.1 EX
						array("English", "6.1", "DTS", "ES"), // English (6.1 DTS ES)
						array("English", "6.1"),
						array("English", "DTS"));
			
			$amazon_audio_lang_map = array(
						array("English"),
						array("French"),
						array("Spanish"),
						array("German"));
						
			while(list(,$audio_lang) = @each($audio_lang_r)) {
				$key = parse_language_info($audio_lang, $amazon_dvd_audio_map);
				if($key!==NULL) {
					$this->addItemAttribute('dvd_audio', $key);
				}
				
				$key = parse_language_info($audio_lang, $amazon_audio_lang_map);
				if($key!==NULL) {
					$this->addItemAttribute('audio_lang', $key);
				}
			}
		}

		if(preg_match("!<li><b>Subtitles:</b>[\s]*(.*?)</li>!i", $pageBuffer, $regs))
		{
			$amazon_video_subtitle_map = array(
						array("English"),
						array("French"),
						array("Spanish"),
						array("German"));
						
			$audio_lang_r = explode(',', $regs[1]);
			
			while(list(,$audio_lang) = @each($audio_lang_r)) {
				$key = parse_language_info($audio_lang, $amazon_video_subtitle_map);
				if($key!==NULL) {
					$this->addItemAttribute('subtitles', $key);
				}
			}
		}

        // Edition details block - 'dvd_extras' attribute
		if(preg_match("!<b>DVD Features:<\/b><ul>(.*?)<\/ul>!", $pageBuffer, $regs))
		{
		    $dvdFeaturesBlock = $regs[1];
		    
			if(preg_match_all("/<li>(.*)<\/li>/mUi", $dvdFeaturesBlock, $matches))
			{
				$dvd_extras = NULL;

				while(list(,$item) = @each($matches[1]))
				{
					$item = html_entity_decode(strip_tags($item));

					// We may have a hard space here, so get rid of it.
					$item = trim(strtr($item, chr(160), ' '));

					if(strpos($item, "anamorphic")===FALSE &&
								strpos($item, "Available Subtitles")===FALSE &&
								strpos($item, "Available Audio Tracks")===FALSE)
					{
						//Commentary by: director George Cosmatos
						if(strpos($item, "Commentary by")!==FALSE && ends_with($item, "Unknown Format"))
						{
							$item = substr($item, 0, strlen($item)-strlen("Unknown Format"));
						}
						else if(preg_match("/\"([^\"]+)\"/", $item, $reg2))
						{
							$item = $reg2[1];
						}
						
						$dvd_extras[] = $item;
					}
				}

				if(is_array($dvd_extras))
				{
					$this->addItemAttribute('dvd_extras', implode("\n", $dvd_extras));
				}
			}
		}

		// IMDB ID block
		//<A HREF="http://amazon.imdb.com/title/tt0319061/">
		//http://www.amazon.com/gp/redirect.html/103-0177494-1143005?location=http://amazon.imdb.com/title/tt0319061&token=F5BF95E1B869FD4EB1192434BA5B7FECBA8B3718
		//http://amazon.imdb.com/title/tt0319061
		if(preg_match("!http://amazon.imdb.com/title/tt([0-9]+)!is", $pageBuffer, $regs))
		{
			$this->addItemAttribute('imdb_id', $regs[1]);
		}

		// Attempt to include data from IMDB if available - but only for DVD, VHS, etc
		// as IMDB does not work with BOOKS or CD's.
		if(is_numeric($this->getItemAttribute('imdb_id')))
		{
			$sitePlugin =& get_site_plugin_instance('imdb');
			if($sitePlugin !== FALSE)
			{
				if($sitePlugin->queryItem(array('imdb_id'=>$this->getItemAttribute('imdb_id')), $s_item_type))
				{
					// no mapping process is performed here, as no $s_item_type was provided.
					$itemData = $sitePlugin->getItemData();
					if(is_array($itemData))
	      			{
						// merge data in here.
						while(list($key,$value) = each($itemData))
						{
							if($key == 'actors')
								$this->replaceItemAttribute('actors', $value);
							else if($key == 'director')
								$this->replaceItemAttribute('director', $value);
							else if($key == 'year')
								$this->replaceItemAttribute('year', $value);
							else if($key == 'actors')
								$this->replaceItemAttribute('actors', $value);
							else if($key == 'genre')
								$this->replaceItemAttribute('genre', $value);
							else if($key == 'plot') //have to map from imdb to amazon attribute type.
								$this->addItemAttribute('blurb', $value);
							else if($key != 'age_rating' && $key != 'run_time')
								$this->addItemAttribute($key, $value);
						}
					}
				}
			}
		}
	}
}
?>