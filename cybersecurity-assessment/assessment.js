/*
 * Cybersecurity Self Assessment
 * Tracks assessment answers, calculates the current score, and updates visual feedback.
 */

$("[data-cybersecurity-assessment]").each(function () {
  const $assessment = $(this);
  const $questions = $assessment.find("[data-cybersecurity-question]");
  const $score = $assessment.find("[data-cybersecurity-score]");
  const $status = $assessment.find("[data-cybersecurity-status]");
  const $progress = $assessment.find("[data-cybersecurity-progress]");

  const answers = {};
  const questionCount = $questions.length;

  /*  Recalculate the assessment score and update the UI */
  function updateCybersecurityAssessment() {
    const answerValues = Object.values(answers);
    const answeredCount = answerValues.length;

    const score = answerValues.filter(function (answer) {
      return answer === "yes";
    }).length;

    const scorePercent = questionCount
      ? (score / questionCount) * 100
      : 0;

    let statusText = "Answer each question to see where you stand.";

    if (answeredCount > 0) {
      if (score >= 7) {
        statusText = "Future-Ready, you are resilient and evolving";
      } else if (score >= 4) {
        statusText = "Adapting, you have taken steps but gaps remain";
      } else {
        statusText = "Endangered, time to close the gaps fast";
      }
    }

    $score.text(score);
    $status.text(statusText);

    $progress
      .css("width", scorePercent + "%")
      .attr("aria-valuenow", score);
  }

  /* Handle our Yes/No answers. Buttons do not require separate event listeners */
  $assessment.on("click", "[data-cybersecurity-answer]", function () {
    const $answerButton = $(this);
    const $question = $answerButton.closest(
      "[data-cybersecurity-question]"
    );

    const questionId = $question.attr("data-question-id");
    const answer = $answerButton.attr("data-cybersecurity-answer");

    const $answerButtons = $question.find(
      "[data-cybersecurity-answer]"
    );

    answers[questionId] = answer;

    // Reset both answer buttons before applying the selected state.
    $answerButtons
      .removeClass("btn-primary btn-dark")
      .addClass("btn-outline-secondary")
      .attr("aria-pressed", "false");

    $answerButton
      .removeClass("btn-outline-secondary")
      .addClass(answer === "yes" ? "btn-primary" : "btn-dark")
      .attr("aria-pressed", "true");

    updateCybersecurityAssessment();
  });

  /* Expand or collapse the additional guidance associated with an question */
  $assessment.on(
    "click",
    "[data-cybersecurity-question-toggle]",
    function () {
      const $toggle = $(this);

      const $question = $toggle.closest(
        "[data-cybersecurity-question]"
      );

      const $explanation = $question.find(
        "[data-cybersecurity-explanation]"
      );

      const isExpanded =
        $toggle.attr("aria-expanded") === "true";

      $toggle.attr(
        "aria-expanded",
        isExpanded ? "false" : "true"
      );

      $explanation.prop("hidden", isExpanded);
    }
  );

  updateCybersecurityAssessment();
});