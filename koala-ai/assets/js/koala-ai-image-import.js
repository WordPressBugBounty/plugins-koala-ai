(function ($) {
  let allPostIds = [];
  let batchSize = 10;
  let processed = 0;
  let updated = 0;
  let running = false;

  function updateProgress() {
    const percent = allPostIds.length
      ? Math.round((processed / allPostIds.length) * 100)
      : 0;
    $("#koala-ai-progress-bar").css("width", percent + "%");
    $("#koala-ai-progress-text").text(
      `${processed} / ${allPostIds.length} posts processed (${updated} updated)`
    );
  }

  function setStatus(msg, isError) {
    $("#koala-ai-status").html(
      `<span style="color:${isError ? "darkred" : "inherit"}">${msg}</span>`
    );
  }

  function processNextBatch() {
    if (!running) return;
    if (allPostIds.length === 0) {
      setStatus("No posts found.", true);
      $("#koala-ai-progress-container").hide();
      running = false;
      return;
    }
    if (processed >= allPostIds.length) {
      setStatus("Import complete!");
      running = false;
      $("#koala-ai-start-btn").prop("disabled", false);
      return;
    }
    const batch = allPostIds.slice(processed, processed + batchSize);
    $.ajax({
      url: KoalaAIImageImport.restUrl + "process_image_import_batch",
      method: "POST",
      beforeSend: function (xhr) {
        xhr.setRequestHeader("X-WP-Nonce", KoalaAIImageImport.nonce);
      },
      data: JSON.stringify({ post_ids: batch }),
      contentType: "application/json",
      success: function (resp) {
        processed += batch.length;
        if (resp && resp.updated_count) updated += resp.updated_count;
        updateProgress();
        setTimeout(processNextBatch, 200); // slight delay for UI
      },
      error: function (xhr) {
        setStatus(
          "Error processing batch: " +
            (xhr.responseJSON && xhr.responseJSON.message
              ? xhr.responseJSON.message
              : xhr.statusText),
          true
        );
        running = false;
        $("#koala-ai-start-btn").prop("disabled", false);
      },
    });
  }

  $(document).on("click", "#koala-ai-start-btn", function () {
    if (running) return;
    running = true;
    processed = 0;
    updated = 0;
    setStatus("Fetching all post IDs...");
    $("#koala-ai-progress-bar").css("width", "0%");
    $("#koala-ai-progress-container").show();
    $("#koala-ai-start-btn").prop("disabled", true);
    $.ajax({
      url: KoalaAIImageImport.restUrl + "all_post_ids",
      method: "GET",
      beforeSend: function (xhr) {
        xhr.setRequestHeader("X-WP-Nonce", KoalaAIImageImport.nonce);
      },
      success: function (resp) {
        if (resp && resp.post_ids && resp.post_ids.length) {
          allPostIds = resp.post_ids;
          setStatus("Starting import...");
          updateProgress();
          processNextBatch();
        } else {
          setStatus("No posts found.", true);
          $("#koala-ai-progress-container").hide();
          $("#koala-ai-start-btn").prop("disabled", false);
          running = false;
        }
      },
      error: function (xhr) {
        setStatus(
          "Error fetching post IDs: " +
            (xhr.responseJSON && xhr.responseJSON.message
              ? xhr.responseJSON.message
              : xhr.statusText),
          true
        );
        $("#koala-ai-progress-container").hide();
        $("#koala-ai-start-btn").prop("disabled", false);
        running = false;
      },
    });
  });
})(jQuery);
