<?

$key = "asdf1234";

?>
<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
    <title>RateStuff API Test</title>
    <style type="text/css">
    .response {
    	border: 1px dashed orange;
    	padding: 1em;
    	white-space: pre;
    }
    </style>
    <script src="//ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js"></script>
    <script type="text/javascript">

    $(document).ready(function(){

		$('form').submit(function(event){

			event.preventDefault();

			var myForm = jQuery(this);

      $('.response').html("");



			$.post( myForm.attr('action'), myForm.serialize(), function(data) {
				$('.response').append("<strong>Response:</strong> " + data + "<br />");
			})
			.fail(function() {
				$('.response').append("<br />FAILED");
			})
			.always(function() {
				$('.response').append("<br />finished");
			});

		});

	});

    </script>
  </head>
  <body>

  	<form action="http://pi.blurryphoto.com/?key=<?= $key ?>" method="post">

  		<input type="text" name="tag" value="test" />

  		<input type="submit" value="submit">

  	</form>

  	<div class="response"></div>
  
  </body>
</html>