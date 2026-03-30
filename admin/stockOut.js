// // admin/stockOut.js
// lucide.createIcons();

// // Function to open edit modal with user data
// // Function to open edit modal with item data
// function editItem(data) {
//   document.getElementById("stockOutId").value = data.SO_ID;
//   document.getElementById("stock_no").value = data.ITEM_ID;
//   document.getElementById("desc").value = data.ITEM_ID;
//   document.getElementById("off").value = data.OFF_ID;
//   document.getElementById("quantity").value = data.SO_QUANTITY;
//   document.getElementById("remarks").value = data.SO_REMARKS;

//   document.getElementById("modalText").innerText = "update stock out";
//   document.getElementById("modal").classList.remove("hidden");

//   document.getElementById("off").focus();
// }

// // Open modal for adding a new item
// document.getElementById("openModal").addEventListener("click", function () {
//   document.getElementById("form").reset();
//   document.getElementById("stockOutId").value = "";
//   document.getElementById("modal").classList.remove("hidden");
//   document.getElementById("modalText").innerText = "Stock Out";
//     document.getElementById("off").focus();

// });
// // Close modal
// document.getElementById("closeModal").addEventListener("click", function () {
//   document.getElementById("modal").classList.add("hidden");
//   document.getElementById("form").reset(); // Clear form
// });

// // Function to prompt for admin password
// async function promptPassword() {
//   const { value: password } = await Swal.fire({
//     title: "Verification Required",
//     input: "password",
//     inputLabel: "Enter your password to continue",
//     inputPlaceholder: "Password",
//     showCancelButton: true,
//     reverseButtons: true,
//     inputValidator: (value) => {
//       if (!value) {
//         return "Password is required!";
//       }
//     },
//   });

//   return password;
// }

// // Form submission (Add/Edit)
// document
//   .getElementById("form")
//   .addEventListener("submit", async function (event) {
//     event.preventDefault();

//     const formData = {
//       stockOutId: document.getElementById("stockOutId").value.trim(),
//       item_id: document.getElementById("stock_no").value.trim(),
//       off_id: document.getElementById("off").value.trim(),
//       quantity: document.getElementById("quantity").value.trim(),
//       remarks: document.getElementById("remarks").value.trim(),
//       action: document.getElementById("stockOutId").value ? "edit" : "add",
//     };

//     // Validate required fields
//     if (
//       !formData.quantity ||
//       !formData.remarks ||
//       !formData.item_id ||
//       !formData.off_id
//     ) {
//       Swal.fire({
//         icon: "warning",
//         title: "Missing Fields",
//         text: "Please fill in all required fields before submitting.",
//       });
//       return;
//     }

//     let password = null;

//     if (formData.action === "edit") {
//       const result = await Swal.fire({
//         title: "Are you sure?",
//         text: "This action will update the stock in record.",
//         icon: "warning",
//         showCancelButton: true,
//         confirmButtonColor: "#d33",
//         cancelButtonColor: "#3085d6",
//         confirmButtonText: "Yes, update it!",
//         reverseButtons: true,
//       });

//       if (!result.isConfirmed) return;

//       password = await promptPassword();
//       if (!password) return;
//     } else {
//       password = formData.pass;
//     }

//     // Show loading state
//     Swal.fire({
//       title: "Updating...",
//       text: "Please wait while we update the item",
//       allowOutsideClick: false,
//       didOpen: () => {
//         Swal.showLoading();
//       },
//     });

//     fetch("stockOut-php.php", {
//       method: "POST",
//       headers: { "Content-Type": "application/json" },
//       body: JSON.stringify({
//         ...formData,
//         password: password,
//       }),
//     })
//       .then((response) => response.json())
//       .then((data) => {
//         if (data.status === "success") {
//           Swal.fire({
//             icon: "success",
//             title: "Success!",
//             text: data.message,
//           }).then(() => {
//             document.getElementById("form").reset();
//             document.getElementById("modal").classList.add("hidden");
//             setTimeout(() => location.reload(), 100);
//           });
//         } else {
//           Swal.fire({ icon: "error", title: "Error", text: data.message });
//           console.log(formData);
//         }
//       })
//       .catch((error) => {
//         console.error("Fetch error:", error);
//         Swal.fire({
//           icon: "error",
//           title: "Request Failed",
//           text: "An error occurred. Please try again.",
//         });
//         console.log(formData);
//       });
//   });
