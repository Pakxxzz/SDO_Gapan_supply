// admin/inventory-alignment.js

function changeItemsPerPage(newLimit) {
  const vendorFilter = document.getElementById("vendorFilter").value;

  let url = `?page=1&limit=${newLimit}`;

  if (vendorFilter) url += `&vendor=${vendorFilter}`;

  window.location.href = url;
}

// Search functionality
// document.addEventListener("DOMContentLoaded", function () {
//   const searchInput = document.getElementById("searchInput");

//   if (searchInput) {
//     searchInput.addEventListener("input", function () {
//       const filter = searchInput.value.toLowerCase();
//       const batches = document.querySelectorAll(".batch-container");

//       batches.forEach((batch) => {
//         const rows = batch.querySelectorAll("tbody tr");
//         let hasVisibleRow = false;

//         rows.forEach((row) => {
//           const cells = row.querySelectorAll("td");
//           let found = false;

//           for (let i = 0; i < cells.length - 1; i++) {
//             if (cells[i].textContent.toLowerCase().includes(filter)) {
//               found = true;
//               break;
//             }
//           }

//           row.style.display = found ? "" : "none";
//           if (found) hasVisibleRow = true;
//         });

//         batch.style.display = hasVisibleRow ? "" : "none";
//       });
//     });
//   }
// });

// verification function
async function promptPassword() {
  const { value: password } = await Swal.fire({
    title: "Verification Required",
    input: "password",
    inputLabel: "Enter your password to continue",
    inputPlaceholder: "Password",
    showCancelButton: true,
    inputValidator: (value) => {
      if (!value) {
        return "Password is required!";
      }
    },
  });

  return password;
}

async function postBatch(batchNumber) {
  Swal.fire({
    title: "Post This Batch?",
    text: `Are you sure you want to post batch ${batchNumber}? This action cannot be undone.`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonText: "Yes, Post",
    cancelButtonText: "Cancel",
    confirmButtonColor: "#10B981",
    reverseButtons: true,
  }).then(async (result) => {
    if (result.isConfirmed) {
      const password = await promptPassword();
      if (!password) return;

      Swal.fire({
        title: "Updating...",
        text: "Please wait while we update the item",
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading(),
      });

      fetch("inventory-alignment-php.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "post_batch",
          batch: batchNumber,
          password,
        }),
      })
        .then((r) => r.json())
        .then((data) => {
          Swal.close();
          if (data.status === "success") {
            Swal.fire({
              icon: "success",
              title: "Batch Posted",
              text: data.message,
            }).then(() => location.reload());
          } else {
            Swal.fire({
              icon: "error",
              title: "Post Failed",
              text: data.message,
            });
          }
        })
        .catch((err) => {
          Swal.close();
          console.error(err);
          Swal.fire({
            icon: "error",
            title: "Error",
            text: "Something went wrong while posting the batch.",
          });
        });
    }
  });
}

// Approve Batch function
async function approveBatch(batchNumber) {
  Swal.fire({
    title: "Approve This Batch?",
    text: `Are you sure you want to approve batch ${batchNumber}? This action cannot be undone.`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonText: "Yes, Approve Batch",
    cancelButtonText: "Cancel",
    confirmButtonColor: "#10B981",
  }).then(async (result) => {
    if (result.isConfirmed) {
      const password = await promptPassword();
      if (!password) return;

      // Show loading state
      Swal.fire({
        title: "Updating...",
        text: "Please wait while we update the item",
        allowOutsideClick: false,
        didOpen: () => {
          Swal.showLoading();
        },
      });

      fetch("inventory-alignment-start.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "approve_batch",
          batch: batchNumber,
          password: password,
        }),
      })
        .then((response) => response.json())
        .then((data) => {
          if (data.status === "success") {
            Swal.fire({
              icon: "success",
              title: "Batch Approved",
              text: data.message,
            }).then(() => {
              location.reload();
            });
          } else {
            Swal.fire({
              icon: "error",
              title: "Approval Failed",
              text: data.message,
            });
          }
        })
        .catch((error) => {
          console.error("Error:", error);
          Swal.fire({
            icon: "error",
            title: "Error",
            text: "Something went wrong while approving the batch.",
          });
        });
    }
  });
}

// Start Alignment button
document
  .getElementById("startAlignment")
  .addEventListener("click", async function () {
    Swal.fire({
      title: "Start New Alignment?",
      text: "This will copy current inventory quantities to the alignment system.",
      icon: "question",
      showCancelButton: true,
      confirmButtonText: "Yes, Start Alignment",
      cancelButtonText: "Cancel",
    }).then(async (result) => {
      if (result.isConfirmed) {
        const password = await promptPassword();
        if (!password) return;

        // Show loading state
        Swal.fire({
          title: "Updating...",
          text: "Please wait while we update the item",
          allowOutsideClick: false,
          didOpen: () => {
            Swal.showLoading();
          },
        });

        fetch("inventory-alignment-start.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            action: "start_alignment",
            password: password,
          }),
        })
          .then((response) => response.json())
          .then((data) => {
            if (data.status === "success") {
              Swal.fire({
                icon: "success",
                title: "Alignment Started",
                text: data.message,
              }).then(() => {
                location.reload();
              });
            } else {
              Swal.fire({
                icon: "error",
                title: "Error",
                text: data.message,
              });
            }
          })
          .catch((error) => {
            console.error("Error:", error);
            Swal.fire({
              icon: "error",
              title: "Error",
              text: "Something went wrong while starting the alignment.",
            });
          });
      }
    });
  });
