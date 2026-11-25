// Profile schedules are edited only inside the profile form.
const selector=document.querySelector("[data-profile-schedule-choice]");
const panel=document.querySelector("[data-profile-schedule-custom]");
if (selector && panel) {
  const fields=[...panel.querySelectorAll("[data-custom-schedule-required]")];
  const sync=()=>{
    const custom=selector.value==="custom";
    panel.hidden=!custom;
    for (const field of fields) {
      field.required=custom;
      field.disabled=!custom;
    }
  };
  selector.addEventListener("change",sync);
  sync();
}
