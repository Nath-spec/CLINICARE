// === Global Variables ===
let chatBox;
let chatInput;
let chatForm;
let receiverInput; // This will hold the selected Doctor's ID
let messagePolling = null; 
let currentlyLoading = false; // Flag to prevent multiple simultaneous loads

// === Message Loading Function ===
async function loadMessages() {
  if (currentlyLoading || typeof CURRENT_USER_ID === 'undefined' || !receiverInput || !receiverInput.value || receiverInput.value === 'null' || receiverInput.value === '') {
      return; 
  }

  currentlyLoading = true; 
  const other_id = receiverInput.value; // Doctor's ID
  
  try {
    const res = await fetch(`../get_messages.php?other_id=${other_id}`); 
    
    if (!res.ok) {
        throw new Error(`Failed to fetch messages. Status: ${res.status} ${res.statusText}`);
    }
    
    const messages = await res.json();
    
    const isScrolledToBottom = chatBox.scrollHeight - chatBox.clientHeight <= chatBox.scrollTop + 50;
    
    chatBox.innerHTML = ""; // Clear existing messages

    if (messages.length === 0) {
      chatBox.innerHTML = `<div style="text-align:center;color:var(--muted); padding: 50px 20px;">No messages yet. Start the conversation!</div>`;
    } else {
      messages.forEach(msg => {
        const div = document.createElement("div");
        const senderId = Number(msg.sender_id); 
        
        div.className = senderId === CURRENT_USER_ID ? "msg outgoing" : "msg incoming"; 
        
        let timeString = 'Sending...'; 
        try {
            const timestampValue = msg.timestamp || msg.created_at; 
            if (timestampValue) {
                // MySQL timestamps need 'T' and 'Z' to be parsed correctly as UTC
                const time = new Date(timestampValue.replace(' ', 'T') + 'Z'); 
                 if (!isNaN(time.getTime())) { 
                    timeString = time.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
                 } else {
                     timeString = "--:-- --"; 
                 }
            } else {
                 timeString = "--:-- --"; 
            }
        } catch(dateError) {
             timeString = "--:-- --";
        }

        const msgTextDiv = document.createElement("div");
        // 🔴 FIX: Inalis ang maling class name na 'msg.message_text'
        msgTextDiv.textContent = msg.message; 

        const msgTimeDiv = document.createElement("small"); 
        msgTimeDiv.textContent = timeString;

        div.appendChild(msgTextDiv);
        div.appendChild(msgTimeDiv);
        chatBox.appendChild(div);
      });
      
      if (isScrolledToBottom) {
           setTimeout(() => { chatBox.scrollTop = chatBox.scrollHeight; }, 0);
      }
    }
  } catch (err) {
    console.error("Error loading messages:", err);
    chatBox.innerHTML = `<div style="text-align:center;color:red; padding: 50px 20px;">Error loading messages. Please check connection or try again later.</div>`;
  } finally {
      currentlyLoading = false; // Reset loading flag
  }
}

// === Doctor Selection Function ===
function selectDoctor(element, doctorName, doctorId) {
  document.querySelectorAll(".chat-list .chat-item").forEach(item => item.classList.remove("active"));
  element.classList.add("active");
  
  document.getElementById("chatWith").textContent = `Dr. ${doctorName}`; 
  receiverInput.value = doctorId;
  
  // Enable form
  chatForm.style.display = 'flex';
  if(chatInput) chatInput.disabled = false;
  const sendButton = chatForm ? chatForm.querySelector('button') : null;
  if (sendButton) sendButton.disabled = false;

  if (messagePolling) {
      clearInterval(messagePolling);
  }
  
  chatBox.innerHTML = '<div style="text-align:center;color:var(--muted); padding: 50px 20px;">Loading messages...</div>'; 
  loadMessages().then(() => {
      messagePolling = setInterval(loadMessages, 3000); 
  });
  
  const url = new URL(window.location);
  url.searchParams.set('doctor_id', doctorId);
  window.history.pushState({}, '', url);
}

// === Initialization and Event Listeners ===
document.addEventListener("DOMContentLoaded", () => {
  
  // === PROFILE DROPDOWN LOGIC ===
  const profileMenu = document.getElementById("profileMenu") || document.getElementById("profileToggle");
  const dropdown = document.getElementById("profileDropdown");
  const caret = profileMenu ? profileMenu.querySelector(".caret") : null;

  if (profileMenu && dropdown && caret) {
      profileMenu.addEventListener("click", (e) => {
          e.stopPropagation();
          dropdown.classList.toggle("show");
          caret.style.transform = dropdown.classList.contains("show") 
              ? "rotate(180deg)" 
              : "rotate(0deg)";
      });

      document.addEventListener("click", (e) => {
          if (profileMenu && !profileMenu.contains(e.target)) {
              dropdown.classList.remove("show");
              caret.style.transform = "rotate(0deg)";
          }
      });
  }
  // === END PROFILE DROPDOWN LOGIC ===

  // === CHAT APPLICATION Initialization (Only run if on chat page) ===
  chatBox = document.getElementById("chatBox");
  chatInput = document.getElementById("chatInput");
  chatForm = document.getElementById("chatForm");
  receiverInput = document.getElementById("recipientId"); 

  if (typeof CURRENT_USER_ID !== 'undefined' && chatBox && chatInput && chatForm && receiverInput) {
      
      // === Message Sending Event Listener ===
      chatForm.addEventListener("submit", async e => {
        e.preventDefault();
        const receiver_id = receiverInput.value; // Doctor's ID
        const message = chatInput.value.trim();
        
        if (!message || !receiver_id || receiver_id === 'null' || receiver_id === '') {
            return;
        }

        const now = new Date();
        const timeString = now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
        const originalMessage = message; 
        
        // --- Optimistic UI Update ---
        const tempDiv = document.createElement("div");
        tempDiv.className = "msg outgoing"; 
        
        const msgTextDiv = document.createElement("div");
        // 🔴 FIX: Inalis ang maling class name na 'msg.message_text'
        msgTextDiv.textContent = originalMessage; 

        const msgTimeDiv = document.createElement("small"); 
        msgTimeDiv.textContent = timeString + " (Sending...)"; 

        tempDiv.appendChild(msgTextDiv);
        tempDiv.appendChild(msgTimeDiv);

        const placeholder = chatBox.querySelector('p[style*="text-align:center"]');
        if (placeholder) {
            chatBox.innerHTML = ''; 
        }
        
        chatBox.appendChild(tempDiv);
        chatBox.scrollTop = chatBox.scrollHeight; 
        chatInput.value = ""; 
        // --- End Optimistic Update ---

        try {
          const res = await fetch("../send_message.php", { 
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ receiver_id: receiver_id, message: originalMessage })
          });
          
          if (!res.ok) { 
              throw new Error(`Server responded with status: ${res.status}`);
          }

          const data = await res.json(); 

          if (data.success) { 
             msgTimeDiv.textContent = timeString;
             setTimeout(loadMessages, 500);
          } else {
            console.error("Patient: Failed to send message:", data.error);
            msgTimeDiv.textContent = timeString + " (Failed)"; 
            msgTimeDiv.style.color = "red";
          }
        } catch (error) {
          console.error("Patient: Connection Error:", error);
          msgTimeDiv.textContent = timeString + " (Connection Error)"; 
          msgTimeDiv.style.color = "orange";
        }
      });

      // === Initial Load & Polling ===
      const initialRecipientId = (typeof SELECTED_DOCTOR_ID !== 'undefined') ? SELECTED_DOCTOR_ID : null; 
      
      if (initialRecipientId && initialRecipientId !== 'null') {
        loadMessages(); // Load initial messages
        messagePolling = setInterval(loadMessages, 3000); 
      } else {
         if (!chatBox.querySelector('p[style*="text-align:center"]')) { 
             chatBox.innerHTML = "<p style='text-align: center; color: var(--muted); margin-top: auto; margin-bottom: auto;'>Select a doctor from the list to start chatting.</p>";
         }
        // Disable form if no recipient
        chatForm.style.display = 'none';
        chatInput.disabled = true;
        chatForm.querySelector('button').disabled = true;
      }
  }
});
