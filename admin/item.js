// admin/item.js
lucide.createIcons();

// Function to open edit modal with item data
function editItem(item) {
  document.getElementById("itemId").value = item.ITEM_ID;
  document.getElementById("itemCode").value = item.ITEM_CODE;
  document.getElementById("desc").value = item.ITEM_DESC;
  document.getElementById("unit").value = item.ITEM_UNIT;

  // Load threshold data
  loadItemThresholds(item.ITEM_ID);

  document.getElementById("modalText").innerText = "Edit Item";
  document.getElementById("modal").classList.remove("hidden");
  document.getElementById("desc").focus();
}

// Function to load item thresholds
function loadItemThresholds(itemId) {
  fetch("get_item_thresholds.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ item_id: itemId }),
  })
    .then((response) => response.json()) // parse JSON directly
    .then((data) => {
      // console.log("Parsed threshold data:", data);
      if (data.status === "success") {
        document.getElementById("minThreshold").value =
          data.min_threshold || "";
        document.getElementById("maxThreshold").value =
          data.max_threshold || "";
      } else {
        document.getElementById("minThreshold").value = "";
        document.getElementById("maxThreshold").value = "";
      }
    })
    .catch((error) => {
      console.error("Error loading thresholds:", error);
      document.getElementById("minThreshold").value = "";
      document.getElementById("maxThreshold").value = "";
    });
}

// Open modal for adding a new item
document.getElementById("openModal").addEventListener("click", function () {
  document.getElementById("form").reset();
  document.getElementById("itemId").value = "";
  document.getElementById("minThreshold").value = "";
  document.getElementById("maxThreshold").value = "";

  document.getElementById("modal").classList.remove("hidden");
  document.getElementById("modalText").innerText = "Add New Item";
  document.getElementById("desc").focus();
});
// Close modal
document.getElementById("closeModal").addEventListener("click", function () {
  document.getElementById("modal").classList.add("hidden");
  document.getElementById("form").reset(); // Clear form
});

// Function to prompt for admin password
async function promptPassword() {
  const { value: password } = await Swal.fire({
    title: "Verification Required",
    input: "password",
    inputLabel: "Enter your password to continue",
    inputPlaceholder: "Password",
    showCancelButton: true,
    reverseButtons: true,
    inputValidator: (value) => {
      if (!value) {
        return "Password is required!";
      }
    },
  });

  return password;
}

// Form submission (Add/Edit)
document
  .getElementById("form")
  .addEventListener("submit", async function (event) {
    event.preventDefault();

    const formData = {
      item_id: document.getElementById("itemId").value.trim(),
      itemCode: document.getElementById("itemCode").value.trim(),
      // barCase: document.getElementById("barCase").value.trim(),
      // barPiece: document.getElementById("barPiece").value.trim(),
      desc: document.getElementById("desc").value.trim(),
      unit: document.getElementById("unit").value.trim(),
      // principal: document.getElementById("principal").value.trim(),
      minThreshold: document.getElementById("minThreshold").value.trim(),
      maxThreshold: document.getElementById("maxThreshold").value.trim(),
      action: document.getElementById("itemId").value ? "edit" : "add",
    };

    // Validate required fields
    if (
      !formData.itemCode ||
      !formData.desc ||
      !formData.unit ||
      formData.minThreshold === "" ||
      formData.maxThreshold === ""
    ) {
      Swal.fire({
        icon: "warning",
        title: "Missing Fields",
        text: "Please fill in all required fields before submitting.",
      });
      return;
    }

    // Validate thresholds
    if (
      formData.minThreshold &&
      formData.maxThreshold &&
      parseInt(formData.minThreshold) >= parseInt(formData.maxThreshold)
    ) {
      Swal.fire({
        icon: "warning",
        title: "Invalid Thresholds",
        text: "Minimum threshold must be less than maximum threshold.",
      });
      return;
    }

    // const password = await promptPassword();
    // if (!password) return;

    // Show loading state
    Swal.fire({
      title: "Updating...",
      text: "Please wait while we update the item",
      allowOutsideClick: false,
      didOpen: () => {
        Swal.showLoading();
      },
    });

    fetch("item-php.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        ...formData,
        // password: password,
      }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.status === "success") {
          Swal.fire({
            icon: "success",
            title: "Success!",
            text: data.message,
          }).then(() => {
            document.getElementById("form").reset();
            document.getElementById("modal").classList.add("hidden");
            setTimeout(() => location.reload(), 100);
          });
        } else {
          Swal.fire({ icon: "error", title: "Error", text: data.message });
        }
      })
      .catch((error) => {
        console.error("Fetch error:", error);
        Swal.fire({
          icon: "error",
          title: "Request Failed",
          text: "An error occurred. Please try again.",
        });
        console.log(formData);
      });
  });

// Delete user function
async function deleteItem(itemId) {
  if (!itemId) {
    Swal.fire({
      icon: "warning",
      title: "Something is missing",
      text: "Item ID is required to archive a item.",
    });
    return;
  }

  Swal.fire({
    title: "Are you sure?",
    text: "This action cannot be undone!",
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#3085d6",
    confirmButtonText: "Yes, archive it!",
    reverseButtons: true,
  }).then(async (result) => {
    if (result.isConfirmed) {
      // Show loading state

      const password = await promptPassword();
      if (!password) return;
      Swal.fire({
        title: "Updating...",
        text: "Please wait while we update the item",
        allowOutsideClick: false,
        didOpen: () => {
          Swal.showLoading();
        },
      });
      fetch("item-php.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          item_id: itemId,
          action: "archive",
          sa_email: password.email,
          password: password,
        }),
      })
        .then((response) => response.json())
        .then((data) => {
          if (data.status === "success") {
            Swal.fire({
              icon: "success",
              title: "Archived!",
              text: data.message,
            }).then(() => location.reload());
          } else {
            Swal.fire({ icon: "error", title: "Error", text: data.message });
          }
        })
        .catch((error) => {
          console.error("Error:", error);
          Swal.fire({
            icon: "error",
            title: "Request Failed",
            text: "An error occurred. Please try again.",
          });
        });
    }
  });
}

// Search functionality
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
