lucide.createIcons();

// function for searching users
// document.addEventListener("DOMContentLoaded", function () {
//   let searchInput = document.getElementById("searchInput");

//   searchInput.addEventListener("input", function () {
//     let filter = searchInput.value.toLowerCase();
//     let rows = document.querySelectorAll("#table-body tr");

//     let hasVisibleRow = false;

//     rows.forEach((row) => {
//       let cells = row.getElementsByTagName("td");
//       let found = false;

//       for (let i = 0; i < cells.length - 1; i++) {
//         if (cells[i].textContent.toLowerCase().includes(filter)) {
//           found = true;
//           break;
//         }
//       }

//       row.style.display = found ? "" : "none";
//       if (found) hasVisibleRow = true;
//     });

//     let noDataRow = document.getElementById("no-data-row");
//     if (noDataRow) noDataRow.style.display = hasVisibleRow ? "none" : "";
//   });
// });
